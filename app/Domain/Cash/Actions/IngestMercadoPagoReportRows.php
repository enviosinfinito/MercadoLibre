<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Models\CashLedgerEntry;
use App\Models\Connection;
use Carbon\CarbonImmutable;
use Laravel\Telescope\Telescope;

/**
 * @phpstan-type ReportRow array<string, string|null>
 */
final class IngestMercadoPagoReportRows
{
    private const CHUNK_SIZE = 250;

    /** @var list<string> */
    private const SKIP_RECORD_TYPES = [
        'initial_available_balance',
        'subtotal',
        'total',
    ];

    /** @var list<string> */
    private const RELEASE_PAYLOAD_KEYS = [
        'DATE',
        'SOURCE_ID',
        'EXTERNAL_REFERENCE',
        'RECORD_TYPE',
        'DESCRIPTION',
        'NET_CREDIT_AMOUNT',
        'NET_DEBIT_AMOUNT',
        'GROSS_AMOUNT',
        'MP_FEE_AMOUNT',
        'TAXES_AMOUNT',
        'BALANCE_AMOUNT',
        'PAYMENT_METHOD',
        '_report_shape',
    ];

    /**
     * @param  list<ReportRow>  $rows
     * @return array{created: int, updated: int}
     */
    public function execute(Connection $connection, string $reportKind, array $rows): array
    {
        $created = 0;
        $updated = 0;
        $provenance = $reportKind === 'release'
            ? 'mp_release_report'
            : 'mp_settlement_report';

        $stopTelescope = class_exists(Telescope::class);
        if ($stopTelescope) {
            Telescope::stopRecording();
        }

        $settlementNets = $reportKind === 'release'
            ? $this->loadSettlementNetsBySource($connection, $rows)
            : [];

        try {
            foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
                $prepared = [];
                foreach ($chunk as $index => $row) {
                    $mapped = $this->mapRow($connection, $reportKind, $provenance, $row, $index, $settlementNets);
                    if ($mapped !== null) {
                        $prepared[] = $mapped;
                    }
                }

                if ($prepared === []) {
                    continue;
                }

                $toUpsert = [];
                $now = now()->toDateTimeString();
                $idemKeys = [];

                foreach ($prepared as $attrs) {
                    $idemKeys[] = $attrs['idempotency_key'];
                    $toUpsert[] = array_merge($this->toDbAttributes($attrs), [
                        'connection_id' => $connection->id,
                        'idempotency_key' => $attrs['idempotency_key'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $existingBefore = CashLedgerEntry::query()
                    ->where('connection_id', $connection->id)
                    ->whereIn('idempotency_key', $idemKeys)
                    ->count();

                foreach (array_chunk($toUpsert, 100) as $batch) {
                    CashLedgerEntry::query()->upsert(
                        $batch,
                        ['connection_id', 'idempotency_key'],
                        [
                            'workspace_id',
                            'entry_type',
                            'transaction_type',
                            'external_source_id',
                            'external_order_id',
                            'external_reference',
                            'external_shipping_id',
                            'gross_amount',
                            'fee_amount',
                            'shipping_fee_amount',
                            'tax_amount',
                            'financing_fee_amount',
                            'net_amount',
                            'currency_code',
                            'occurred_at',
                            'released_at',
                            'is_released',
                            'provenance',
                            'payload',
                            'updated_at',
                        ],
                    );
                }

                $existingAfter = CashLedgerEntry::query()
                    ->where('connection_id', $connection->id)
                    ->whereIn('idempotency_key', $idemKeys)
                    ->count();
                $chunkCreated = max(0, $existingAfter - $existingBefore);
                $created += $chunkCreated;
                $updated += max(0, count($prepared) - $chunkCreated);

                unset($chunk, $prepared, $toUpsert, $idemKeys);
            }
        } finally {
            if ($stopTelescope) {
                Telescope::startRecording();
            }
        }

        return compact('created', 'updated');
    }

    /**
     * @param  ReportRow  $row
     * @param  array<string, string>  $settlementNets
     * @return array<string, mixed>|null
     */
    private function mapRow(
        Connection $connection,
        string $reportKind,
        string $provenance,
        array $row,
        int $index,
        array $settlementNets,
    ): ?array {
        if ($reportKind === 'release') {
            return $this->mapReleaseRow($connection, $provenance, $row, $index, $settlementNets);
        }

        return $this->mapSettlementRow($connection, $provenance, $row, $index);
    }

    /**
     * @param  ReportRow  $row
     * @param  array<string, string>  $settlementNets
     * @return array<string, mixed>|null
     */
    private function mapReleaseRow(
        Connection $connection,
        string $provenance,
        array $row,
        int $index,
        array $settlementNets,
    ): ?array {
        $recordType = strtolower(trim((string) ($row['RECORD_TYPE'] ?? '')));
        if ($recordType !== '' && in_array($recordType, self::SKIP_RECORD_TYPES, true)) {
            return null;
        }

        $description = strtolower(trim((string) ($row['DESCRIPTION'] ?? '')));
        $sourceId = $this->firstNonEmpty($row, ['SOURCE_ID', 'PAYMENT_ID']);
        $dateRaw = $this->firstNonEmpty($row, ['DATE']);
        if ($sourceId === null && $dateRaw === null && $description === '' && $recordType === '') {
            return null;
        }

        $credit = CashMoney::scale($this->firstNonEmpty($row, ['NET_CREDIT_AMOUNT'])) ?? CashMoney::zero();
        $debit = CashMoney::scale($this->firstNonEmpty($row, ['NET_DEBIT_AMOUNT'])) ?? CashMoney::zero();
        $net = CashMoney::sub($credit, $debit);

        $isReserve = (($row['_report_shape'] ?? '') === 'reserve')
            || (! array_key_exists('NET_CREDIT_AMOUNT', $row) && ! array_key_exists('NET_DEBIT_AMOUNT', $row));

        if ($isReserve && CashMoney::cmp($net, '0') === 0 && is_string($sourceId) && isset($settlementNets[$sourceId])) {
            $net = $settlementNets[$sourceId];
        }

        $transactionType = $description !== '' ? strtoupper($description) : ($recordType !== '' ? strtoupper($recordType) : 'RELEASE');
        $entryType = $this->mapEntryType($transactionType, 'release');
        $occurredAt = $this->parseDate($dateRaw);

        $idem = implode('|', [
            $provenance,
            $transactionType,
            (string) ($sourceId ?? 'row'.$index),
            (string) ($dateRaw ?? ''),
            $credit,
            $debit,
        ]);

        $payload = $this->slimReleasePayload($row);
        if ($isReserve) {
            $payload['_report_shape'] = 'reserve';
        }

        return [
            'workspace_id' => $connection->workspace_id,
            'entry_type' => $entryType,
            'transaction_type' => $transactionType,
            'external_source_id' => $sourceId,
            'external_order_id' => $this->firstNonEmpty($row, ['EXTERNAL_REFERENCE']),
            'external_reference' => $this->firstNonEmpty($row, ['EXTERNAL_REFERENCE']),
            'external_shipping_id' => null,
            'gross_amount' => CashMoney::scale($this->firstNonEmpty($row, ['GROSS_AMOUNT'])),
            'fee_amount' => CashMoney::scale($this->firstNonEmpty($row, ['MP_FEE_AMOUNT', 'FEE_AMOUNT'])),
            'shipping_fee_amount' => null,
            'tax_amount' => CashMoney::scale($this->firstNonEmpty($row, ['TAXES_AMOUNT', 'TAX_AMOUNT'])),
            'financing_fee_amount' => null,
            'net_amount' => $net,
            'currency_code' => 'MXN',
            'occurred_at' => $occurredAt,
            'released_at' => $occurredAt,
            'is_released' => $recordType === '' || $recordType === 'release' ? true : null,
            'provenance' => $provenance,
            'idempotency_key' => $idem,
            'payload' => $payload,
        ];
    }

    /**
     * @param  ReportRow  $row
     * @return array<string, mixed>
     */
    private function mapSettlementRow(
        Connection $connection,
        string $provenance,
        array $row,
        int $index,
    ): array {
        $transactionType = strtoupper((string) ($row['TRANSACTION_TYPE'] ?? ''));
        $entryType = $this->mapEntryType($transactionType, 'settlement');
        $sourceId = $this->firstNonEmpty($row, ['SOURCE_ID', 'PAYMENT_ID', 'EXTERNAL_REFERENCE']);
        $net = CashMoney::scale($this->firstNonEmpty($row, ['SETTLEMENT_NET_AMOUNT', 'REAL_AMOUNT', 'NET_CREDIT_AMOUNT', 'TRANSACTION_AMOUNT']))
            ?? CashMoney::zero();

        $idem = implode('|', [
            $provenance,
            $transactionType !== '' ? $transactionType : $entryType,
            (string) ($sourceId ?? 'row'.$index),
            (string) ($row['SETTLEMENT_DATE'] ?? $row['DATE'] ?? $row['MONEY_RELEASE_DATE'] ?? ''),
            $net,
        ]);

        return [
            'workspace_id' => $connection->workspace_id,
            'entry_type' => $entryType,
            'transaction_type' => $transactionType !== '' ? $transactionType : null,
            'external_source_id' => $sourceId,
            'external_order_id' => $this->firstNonEmpty($row, ['ORDER_ID', 'EXTERNAL_REFERENCE', 'ORDER_MP']),
            'external_reference' => $this->firstNonEmpty($row, ['EXTERNAL_REFERENCE']),
            'external_shipping_id' => $this->firstNonEmpty($row, ['SHIPPING_ID']),
            'gross_amount' => CashMoney::scale($this->firstNonEmpty($row, ['TRANSACTION_AMOUNT'])),
            'fee_amount' => CashMoney::scale($this->firstNonEmpty($row, ['MKP_FEE_AMOUNT', 'FEE_AMOUNT', 'MERCADOPAGO_FEE_AMOUNT'])),
            'shipping_fee_amount' => CashMoney::scale($this->firstNonEmpty($row, ['SHIPPING_FEE_AMOUNT'])),
            'tax_amount' => CashMoney::scale($this->firstNonEmpty($row, ['TAXES_AMOUNT', 'TAX_AMOUNT'])),
            'financing_fee_amount' => CashMoney::scale($this->firstNonEmpty($row, ['FINANCING_FEE_AMOUNT'])),
            'net_amount' => $net,
            'currency_code' => strtoupper((string) ($row['SETTLEMENT_CURRENCY'] ?? $row['TRANSACTION_CURRENCY'] ?? 'MXN')) ?: 'MXN',
            'occurred_at' => $this->parseDate($this->firstNonEmpty($row, ['SETTLEMENT_DATE', 'DATE', 'TRANSACTION_DATE'])),
            'released_at' => $this->parseDate($this->firstNonEmpty($row, ['MONEY_RELEASE_DATE'])),
            'is_released' => $this->resolveIsReleased($row),
            'provenance' => $provenance,
            'idempotency_key' => $idem,
            'payload' => $row,
        ];
    }

    /**
     * @param  list<ReportRow>  $rows
     * @return array<string, string> source_id => net_amount
     */
    private function loadSettlementNetsBySource(Connection $connection, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $this->firstNonEmpty($row, ['SOURCE_ID']);
            if ($id !== null) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $entries = CashLedgerEntry::query()
                ->where('connection_id', $connection->id)
                ->where('entry_type', 'settlement')
                ->whereIn('external_source_id', $chunk)
                ->get(['external_source_id', 'net_amount']);
            foreach ($entries as $entry) {
                $sid = (string) $entry->external_source_id;
                if ($sid === '' || isset($map[$sid])) {
                    continue;
                }
                $map[$sid] = CashMoney::scale((string) $entry->net_amount) ?? CashMoney::zero();
            }
        }

        return $map;
    }

    /**
     * @param  ReportRow  $row
     * @return array<string, string|null>
     */
    private function slimReleasePayload(array $row): array
    {
        $out = [];
        foreach (self::RELEASE_PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = is_scalar($row[$key]) || $row[$key] === null
                    ? (is_string($row[$key]) || $row[$key] === null ? $row[$key] : (string) $row[$key])
                    : null;
            }
        }

        return $out;
    }

    /**
     * @param  ReportRow  $row
     */
    private function resolveIsReleased(array $row): ?bool
    {
        $explicit = $this->parseBool($row['IS_RELEASED'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $releasedAt = $this->parseDate($this->firstNonEmpty($row, ['MONEY_RELEASE_DATE']));
        if ($releasedAt === null) {
            return null;
        }

        return $releasedAt->lte(CarbonImmutable::now());
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function toDbAttributes(array $attrs): array
    {
        $isReleased = $attrs['is_released'];

        return [
            'workspace_id' => $attrs['workspace_id'],
            'entry_type' => $attrs['entry_type'],
            'transaction_type' => $attrs['transaction_type'],
            'external_source_id' => $attrs['external_source_id'],
            'external_order_id' => $attrs['external_order_id'],
            'external_reference' => $attrs['external_reference'],
            'external_shipping_id' => $attrs['external_shipping_id'],
            'gross_amount' => $attrs['gross_amount'],
            'fee_amount' => $attrs['fee_amount'],
            'shipping_fee_amount' => $attrs['shipping_fee_amount'],
            'tax_amount' => $attrs['tax_amount'],
            'financing_fee_amount' => $attrs['financing_fee_amount'],
            'net_amount' => $attrs['net_amount'],
            'currency_code' => $attrs['currency_code'],
            'occurred_at' => $attrs['occurred_at'] instanceof CarbonImmutable
                ? $attrs['occurred_at']->toDateTimeString()
                : $attrs['occurred_at'],
            'released_at' => $attrs['released_at'] instanceof CarbonImmutable
                ? $attrs['released_at']->toDateTimeString()
                : $attrs['released_at'],
            'is_released' => $isReleased === null ? null : ($isReleased ? 1 : 0),
            'provenance' => $attrs['provenance'],
            'payload' => json_encode($attrs['payload'], JSON_THROW_ON_ERROR),
        ];
    }

    private function mapEntryType(string $transactionType, string $reportKind): string
    {
        $upper = strtoupper($transactionType);

        return match (true) {
            $upper === 'WITHDRAWAL',
            $upper === 'PAYOUTS',
            $upper === 'PAYOUT',
            $upper === 'BANK_TRANSFER' => 'withdrawal',
            $upper === 'REFUND', $upper === 'REFUND_SHIPPING' => 'refund',
            $upper === 'CHARGEBACK', $upper === 'CHARGEBACK_SHIPPING' => 'chargeback',
            $upper === 'SETTLEMENT', $upper === 'SETTLEMENT_SHIPPING' => 'settlement',
            str_starts_with($upper, 'RESERVE_'),
            $upper === 'MEDIATION',
            $upper === 'DISPUTE',
            str_contains($upper, 'DISPUTE') => 'release',
            $reportKind === 'release' => 'release',
            str_contains($upper, 'TAX') => 'tax',
            str_contains($upper, 'SHIPPING') && ! str_contains($upper, 'RESERVE') && ! str_starts_with($upper, 'REFUND') => 'shipping_fee',
            $upper !== '' => 'adjustment',
            default => $reportKind === 'release' ? 'release' : 'settlement',
        };
    }

    /**
     * @param  ReportRow  $row
     * @param  list<string>  $keys
     */
    private function firstNonEmpty(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));
        if (in_array($normalized, ['TRUE', '1', 'YES', 'SI', 'SÍ'], true)) {
            return true;
        }
        if (in_array($normalized, ['FALSE', '0', 'NO'], true)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
