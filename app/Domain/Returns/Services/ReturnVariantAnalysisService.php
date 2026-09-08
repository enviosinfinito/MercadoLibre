<?php

namespace App\Domain\Returns\Services;

use App\Domain\Sales\Support\OrderSalesClassification;
use App\Models\OrderLine;
use App\Models\ReturnCaseItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReturnVariantAnalysisService
{
    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    public function forProduct(
        int $workspaceId,
        ?int $productId,
        ?string $mlItemId,
        Carbon $start,
        Carbon $end,
        ?array $connectionIds = null,
    ): array {
        $soldQuery = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->whereBetween('orders.ordered_at', [$start, $end])
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses());

        if ($connectionIds) {
            $soldQuery->whereIn('order_lines.connection_id', $connectionIds);
        }
        if ($productId !== null) {
            $soldQuery->where('variants.product_id', $productId);
        } elseif (filled($mlItemId)) {
            $soldQuery->where('order_lines.external_item_id', $mlItemId);
        }

        $sold = $soldQuery
            ->select([
                'order_lines.variant_id',
                'order_lines.external_variation_id',
                'order_lines.sku',
                DB::raw('SUM(order_lines.quantity) as units_sold'),
            ])
            ->groupBy('order_lines.variant_id', 'order_lines.external_variation_id', 'order_lines.sku')
            ->get()
            ->keyBy(fn ($r) => ($r->variant_id ?: '0').'|'.($r->external_variation_id ?: ''));

        $retQuery = ReturnCaseItem::query()
            ->join('returns', 'returns.id', '=', 'return_case_items.return_id')
            ->where('return_case_items.workspace_id', $workspaceId)
            ->whereBetween('returns.opened_at', [$start, $end]);
        if ($connectionIds) {
            $retQuery->whereIn('returns.connection_id', $connectionIds);
        }
        if ($productId !== null) {
            $retQuery->where('return_case_items.product_id', $productId);
        } else {
            $retQuery->where('return_case_items.ml_item_id', $mlItemId);
        }

        $returned = $retQuery
            ->select([
                'return_case_items.variant_id',
                'return_case_items.ml_variation_id',
                'return_case_items.variant_label',
                'return_case_items.sku',
                DB::raw('SUM(return_case_items.quantity) as returned_units'),
                DB::raw('SUM(return_case_items.line_amount) as returned_amount'),
            ])
            ->groupBy(
                'return_case_items.variant_id',
                'return_case_items.ml_variation_id',
                'return_case_items.variant_label',
                'return_case_items.sku',
            )
            ->get()
            ->keyBy(fn ($r) => ($r->variant_id ?: '0').'|'.($r->ml_variation_id ?: ''));

        $keys = $sold->keys()->merge($returned->keys())->unique();
        $rows = [];
        $rates = [];

        foreach ($keys as $key) {
            $s = $sold->get($key);
            $r = $returned->get($key);
            $unitsSold = (int) ($s->units_sold ?? 0);
            $returnedUnits = (int) ($r->returned_units ?? 0);
            $rate = $unitsSold > 0 ? $returnedUnits / $unitsSold : ($returnedUnits > 0 ? 1.0 : 0.0);
            $rates[] = $rate;
            $rows[] = [
                'variant_id' => $s->variant_id ?? $r->variant_id ?? null,
                'ml_variation_id' => $s->external_variation_id ?? $r->ml_variation_id ?? null,
                'label' => $r->variant_label ?? ($s->sku ?? $r->sku ?? 'Variante'),
                'sku' => $s->sku ?? $r->sku ?? null,
                'units_sold' => $unitsSold,
                'returned_units' => $returnedUnits,
                'returned_amount' => round((float) ($r->returned_amount ?? 0), 2),
                'return_rate' => round($rate, 4),
            ];
        }

        $peer = null;
        if ($rates !== []) {
            sort($rates);
            $peer = $rates[(int) floor((count($rates) - 1) / 2)];
        }

        foreach ($rows as &$row) {
            $row['outlier_multiplier'] = ($peer !== null && $peer > 0)
                ? round($row['return_rate'] / $peer, 1)
                : null;
            $row['is_outlier'] = $row['outlier_multiplier'] !== null
                && $row['outlier_multiplier'] >= (float) config('returns.anomaly.variant_outlier_multiplier', 3)
                && $row['units_sold'] >= 5;
        }
        unset($row);

        usort($rows, fn ($a, $b) => $b['return_rate'] <=> $a['return_rate']);

        return $rows;
    }
}
