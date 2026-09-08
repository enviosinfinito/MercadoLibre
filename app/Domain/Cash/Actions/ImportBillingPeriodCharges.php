<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Integrations\Support\LoggedHttpClient;
use App\Models\CashLedgerEntry;
use App\Models\Connection;
use App\Models\ConnectionCapability;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Import ML Billing period summary charges when capability allows (graceful on 403).
 *
 * @return array{imported: int, skipped: bool, reason: string|null}
 */
final class ImportBillingPeriodCharges
{
    public function __construct(
        private readonly LoggedHttpClient $http,
        private readonly EnsureFreshConnectionToken $ensureToken,
    ) {}

    /**
     * @return array{imported: int, skipped: bool, reason: string|null}
     */
    public function execute(Connection $connection, ?string $periodKey = null): array
    {
        $capabilityKey = (string) config('finance.cash.capability_keys.billing');
        $capability = ConnectionCapability::query()
            ->where('connection_id', $connection->id)
            ->where('capability_key', $capabilityKey)
            ->first();

        if ($capability !== null && ! $capability->enabled) {
            return ['imported' => 0, 'skipped' => true, 'reason' => 'capability_disabled'];
        }

        try {
            $token = $this->ensureToken->execute($connection->loadMissing('credential'));
        } catch (Throwable $e) {
            return ['imported' => 0, 'skipped' => true, 'reason' => 'token:'.mb_substr($e->getMessage(), 0, 80)];
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $periodKey ??= CarbonImmutable::now()->startOfMonth()->format('Y-m-d');

        $summaryUrl = $base.'/billing/integration/periods/key/'.$periodKey.'/summary/details';
        $response = $this->http->get($summaryUrl, ['group' => 'ML'], $token, 30);

        if ($response->status() === 403 || $response->status() === 401) {
            ConnectionCapability::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'capability_key' => $capabilityKey],
                [
                    'workspace_id' => $connection->workspace_id,
                    'enabled' => false,
                    'meta' => [
                        'http_status' => $response->status(),
                        'note' => 'billing summary forbidden',
                        'probed_at' => now()->toIso8601String(),
                    ],
                ],
            );

            return ['imported' => 0, 'skipped' => true, 'reason' => 'http_'.$response->status()];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return ['imported' => 0, 'skipped' => true, 'reason' => 'http_'.$response->status()];
        }

        $json = $response->json();
        $charges = is_array($json['bill_includes']['charges'] ?? null)
            ? $json['bill_includes']['charges']
            : [];

        $imported = 0;
        foreach ($charges as $charge) {
            if (! is_array($charge)) {
                continue;
            }
            $type = strtoupper((string) ($charge['type'] ?? 'CHARGE'));
            $amount = CashMoney::scale(isset($charge['amount']) ? (string) $charge['amount'] : null);
            if ($amount === null) {
                continue;
            }

            $entryType = match (true) {
                $type === 'PADS' => 'ads_charge',
                str_contains(strtolower((string) ($charge['label'] ?? '')), 'almacen') => 'storage_charge',
                str_contains($type, 'CXD') => 'shipping_fee',
                str_contains($type, 'CV') => 'fee',
                default => 'adjustment',
            };

            $idem = implode('|', ['ml_billing', $periodKey, $type, (string) ($charge['groupId'] ?? ''), $amount]);

            CashLedgerEntry::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'idempotency_key' => $idem,
                ],
                [
                    'workspace_id' => $connection->workspace_id,
                    'entry_type' => $entryType,
                    'transaction_type' => $type,
                    'external_source_id' => null,
                    'external_order_id' => null,
                    'external_reference' => $periodKey,
                    'gross_amount' => $amount,
                    'net_amount' => bcmul($amount, '-1', 6),
                    'currency_code' => 'MXN',
                    'occurred_at' => CarbonImmutable::parse($periodKey)->endOfMonth(),
                    'provenance' => 'ml_billing',
                    'payload' => $charge,
                ],
            );
            $imported++;
        }

        ConnectionCapability::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'capability_key' => $capabilityKey],
            [
                'workspace_id' => $connection->workspace_id,
                'enabled' => true,
                'meta' => [
                    'http_status' => $response->status(),
                    'note' => 'imported '.$imported.' charges for '.$periodKey,
                    'probed_at' => now()->toIso8601String(),
                ],
            ],
        );

        return ['imported' => $imported, 'skipped' => false, 'reason' => null];
    }
}
