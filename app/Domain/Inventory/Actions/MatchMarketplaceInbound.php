<?php

namespace App\Domain\Inventory\Actions;

use App\Models\FullStockOperation;
use App\Models\MarketplaceInbound;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class MatchMarketplaceInbound
{
    public const DATE_WINDOW_DAYS = 10;

    public function execute(FullStockOperation $operation): ?MarketplaceInbound
    {
        if ($operation->operation_type !== 'INBOUND_RECEPTION') {
            return null;
        }

        if ($operation->variant_id === null) {
            return null;
        }

        if ($operation->marketplace_inbound_id !== null) {
            $existing = MarketplaceInbound::query()->find($operation->marketplace_inbound_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($operation) {
            /** @var FullStockOperation $operation */
            $operation = FullStockOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($operation->marketplace_inbound_id !== null) {
                return MarketplaceInbound::query()->find($operation->marketplace_inbound_id);
            }

            $inbound = $this->findCandidate($operation);
            if ($inbound === null) {
                return null;
            }

            /** @var MarketplaceInbound $inbound */
            $inbound = MarketplaceInbound::query()->whereKey($inbound->id)->lockForUpdate()->firstOrFail();
            if ($inbound->full_stock_operation_id !== null && (int) $inbound->full_stock_operation_id !== (int) $operation->id) {
                return null;
            }

            $confirmed = $this->confirmedQty($operation);
            $inbound->qty_confirmed = $confirmed;
            $inbound->full_stock_operation_id = $operation->id;
            $inbound->matched_at = now();
            $inbound->status = $this->statusForQty((string) $inbound->qty_sent, $confirmed);
            if ($inbound->external_inbound_id === null || $inbound->external_inbound_id === '') {
                $refInbound = $operation->inboundIdFromReferences();
                if ($refInbound !== null) {
                    $inbound->external_inbound_id = $refInbound;
                }
            }
            $inbound->save();

            $operation->marketplace_inbound_id = $inbound->id;
            $operation->save();

            return $inbound->fresh();
        });
    }

    private function findCandidate(FullStockOperation $operation): ?MarketplaceInbound
    {
        $pending = MarketplaceInbound::query()
            ->where('workspace_id', $operation->workspace_id)
            ->where('connection_id', $operation->connection_id)
            ->where('variant_id', $operation->variant_id)
            ->where('status', MarketplaceInbound::STATUS_PENDING)
            ->whereNull('full_stock_operation_id')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        if ($pending->isEmpty()) {
            return null;
        }

        $refInbound = $operation->inboundIdFromReferences();
        if ($refInbound !== null) {
            $byId = $pending->first(
                fn (MarketplaceInbound $row) => $row->external_inbound_id !== null
                    && (string) $row->external_inbound_id === $refInbound,
            );
            if ($byId !== null) {
                return $byId;
            }
        }

        $inWindow = $pending->filter(function (MarketplaceInbound $row) use ($operation) {
            if ($operation->occurred_at === null || $row->sent_at === null) {
                return true;
            }

            return Carbon::parse($row->sent_at)->diffInDays(Carbon::parse($operation->occurred_at), true)
                <= self::DATE_WINDOW_DAYS;
        })->values();

        $confirmed = $this->confirmedQty($operation);
        $qtyMatches = $inWindow->filter(
            fn (MarketplaceInbound $row) => $this->qtyClose((string) $row->qty_sent, $confirmed),
        )->values();

        if ($qtyMatches->count() === 1) {
            return $qtyMatches->first();
        }

        return null;
    }

    private function confirmedQty(FullStockOperation $operation): string
    {
        $delta = (string) ($operation->available_quantity_delta ?? '0');
        if (bccomp($delta, '0', 6) < 0) {
            return bcmul($delta, '-1', 6);
        }

        return $delta;
    }

    private function qtyClose(string $sent, string $confirmed): bool
    {
        return bccomp($sent, $confirmed, 6) === 0;
    }

    private function statusForQty(string $sent, string $confirmed): string
    {
        if (bccomp($sent, $confirmed, 6) === 0) {
            return MarketplaceInbound::STATUS_MATCHED;
        }

        return MarketplaceInbound::STATUS_PARTIAL;
    }
}
