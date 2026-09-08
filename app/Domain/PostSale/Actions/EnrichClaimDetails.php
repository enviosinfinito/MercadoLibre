<?php

namespace App\Domain\PostSale\Actions;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Models\Claim;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EnrichClaimDetails
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
    ) {}

    public function execute(Claim $claim, bool $force = false): Claim
    {
        $claim->loadMissing('connection');
        $connection = $claim->connection;
        if ($connection === null || $connection->provider !== 'mercadolibre') {
            return $claim;
        }

        $token = $this->accessToken($connection);
        if ($token === null || $claim->external_claim_id === null || $claim->external_claim_id === '') {
            return $claim;
        }

        /** @var \App\Integrations\MercadoLibre\Connector\MercadoLibreConnector $connector */
        $connector = $this->registry->get('mercadolibre');
        $updates = [];

        if ($force || blank($claim->reason_detail)) {
            $reasonId = $claim->reason_id;
            if (is_string($reasonId) && $reasonId !== '') {
                try {
                    $reason = $connector->fetchClaimReason($reasonId, $token);
                    $detail = $reason['detail'] ?? $reason['name'] ?? $reason['description'] ?? null;
                    if (is_string($detail) && $detail !== '') {
                        $updates['reason_detail'] = $detail;
                        if (blank($claim->reason) || $claim->reason === $reasonId) {
                            $updates['reason'] = $detail;
                        }
                    }
                } catch (Throwable $e) {
                    Log::info('claim.enrich.reason_failed', [
                        'claim_id' => $claim->id,
                        'reason_id' => $reasonId,
                        'error' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }
            }
        }

        // /detail tiene el copy real de ML ("El comprador dijo que…") + título/plazo.
        if ($force || blank($claim->problem) || blank($claim->status_title)) {
            try {
                $detail = $connector->fetchClaimDetail((string) $claim->external_claim_id, $token);
                if (is_string($detail['problem'] ?? null) && $detail['problem'] !== '') {
                    $updates['problem'] = $detail['problem'];
                }
                if (is_string($detail['title'] ?? null) && $detail['title'] !== '') {
                    $updates['status_title'] = $detail['title'];
                }
                if (is_string($detail['description'] ?? null) && $detail['description'] !== '') {
                    $updates['status_description'] = $detail['description'];
                }
                $due = $this->parseDate($detail['due_date'] ?? null);
                if ($due !== null) {
                    $updates['due_at'] = $due;
                }
            } catch (Throwable $e) {
                Log::info('claim.enrich.detail_failed', [
                    'claim_id' => $claim->id,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        if ($force || blank($claim->affects_reputation) || $claim->has_incentive === null) {
            try {
                $rep = $connector->fetchAffectsReputation((string) $claim->external_claim_id, $token);
                if (is_string($rep['affects_reputation'] ?? null) && $rep['affects_reputation'] !== '') {
                    $updates['affects_reputation'] = $rep['affects_reputation'];
                }
                if (array_key_exists('has_incentive', $rep) && is_bool($rep['has_incentive'])) {
                    $updates['has_incentive'] = $rep['has_incentive'];
                }
                $repDue = $this->parseDate($rep['due_date'] ?? null);
                if ($repDue !== null) {
                    $updates['reputation_due_at'] = $repDue;
                }
            } catch (Throwable $e) {
                Log::info('claim.enrich.reputation_failed', [
                    'claim_id' => $claim->id,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        if ($updates !== []) {
            $claim->forceFill($updates)->save();
        }

        return $claim->refresh();
    }

    private function accessToken(\App\Models\Connection $connection): ?string
    {
        try {
            return app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                ->execute($connection);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        return \App\Domain\Shared\Support\ProviderDateTime::parseUtc($value);
    }
}
