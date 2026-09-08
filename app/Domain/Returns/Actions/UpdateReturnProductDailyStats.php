<?php

namespace App\Domain\Returns\Actions;

use App\Domain\Sales\Support\OrderSalesClassification;
use App\Models\OrderLine;
use App\Models\ReturnCaseItem;
use App\Models\ReturnProductDailyStat;
use App\Models\ReturnVariantDailyStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class UpdateReturnProductDailyStats
{
    public function execute(
        int $workspaceId,
        Carbon $date,
        ?int $productId = null,
        ?string $mlItemId = null,
        ?int $connectionId = null,
    ): void {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $dateString = $date->toDateString();

        $soldQuery = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->whereBetween('orders.ordered_at', [$dayStart, $dayEnd])
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses());

        if ($connectionId !== null && $connectionId > 0) {
            $soldQuery->where('order_lines.connection_id', $connectionId);
        }

        if ($productId !== null) {
            $soldQuery->where('variants.product_id', $productId);
        } elseif (filled($mlItemId)) {
            $soldQuery->where('order_lines.external_item_id', $mlItemId);
        }

        $soldRows = $soldQuery
            ->select([
                'variants.product_id as product_id',
                'order_lines.external_item_id as ml_item_id',
                'order_lines.sku as sku',
                'order_lines.connection_id as connection_id',
                DB::raw('SUM(order_lines.quantity) as units_sold'),
                DB::raw('SUM(order_lines.line_total_amount) as gross_sales'),
            ])
            ->groupBy('variants.product_id', 'order_lines.external_item_id', 'order_lines.sku', 'order_lines.connection_id')
            ->get();

        $returnQuery = ReturnCaseItem::query()
            ->join('returns', 'returns.id', '=', 'return_case_items.return_id')
            ->where('return_case_items.workspace_id', $workspaceId)
            ->whereBetween('returns.opened_at', [$dayStart, $dayEnd]);

        if ($connectionId !== null && $connectionId > 0) {
            $returnQuery->where('returns.connection_id', $connectionId);
        }
        if ($productId !== null) {
            $returnQuery->where('return_case_items.product_id', $productId);
        } elseif (filled($mlItemId)) {
            $returnQuery->where('return_case_items.ml_item_id', $mlItemId);
        }

        $returnRows = $returnQuery
            ->select([
                'return_case_items.product_id',
                'return_case_items.ml_item_id',
                'return_case_items.sku',
                'returns.connection_id',
                DB::raw('SUM(return_case_items.quantity) as returned_units'),
                DB::raw('COUNT(DISTINCT returns.id) as return_count'),
                DB::raw('SUM(return_case_items.line_amount) as returned_amount'),
                DB::raw('MAX(COALESCE(returns.inferred_reason_group, returns.reason_group)) as dominant_reason_group'),
            ])
            ->groupBy(
                'return_case_items.product_id',
                'return_case_items.ml_item_id',
                'return_case_items.sku',
                'returns.connection_id',
            )
            ->get()
            ->keyBy(fn ($row) => $this->productKey($row->product_id, $row->ml_item_id));

        $keys = collect($soldRows)
            ->map(fn ($row) => $this->productKey($row->product_id, $row->ml_item_id))
            ->merge($returnRows->keys())
            ->unique()
            ->values();

        foreach ($keys as $key) {
            $sold = $soldRows->first(fn ($row) => $this->productKey($row->product_id, $row->ml_item_id) === $key);
            $ret = $returnRows->get($key);

            $unitsSold = (int) ($sold->units_sold ?? 0);
            $returnedUnits = (int) ($ret->returned_units ?? 0);
            $returnCount = (int) ($ret->return_count ?? 0);
            $grossSales = (float) ($sold->gross_sales ?? 0);
            $returnedAmount = (float) ($ret->returned_amount ?? 0);
            $rate = $unitsSold > 0 ? round($returnedUnits / $unitsSold, 4) : ($returnedUnits > 0 ? 1.0 : 0.0);

            $pid = $sold->product_id ?? $ret->product_id ?? null;
            $mid = $sold->ml_item_id ?? $ret->ml_item_id ?? null;
            $sku = $sold->sku ?? $ret->sku ?? null;
            $conn = $sold->connection_id ?? $ret->connection_id ?? $connectionId;

            $this->upsertProductStat(
                workspaceId: $workspaceId,
                dateString: $dateString,
                productId: $pid !== null ? (int) $pid : null,
                mlItemId: $mid ? (string) $mid : '',
                attributes: [
                    'connection_id' => $conn,
                    'sku' => $sku,
                    'units_sold' => $unitsSold,
                    'gross_sales' => $grossSales,
                    'returned_units' => $returnedUnits,
                    'return_count' => $returnCount,
                    'returned_amount' => $returnedAmount,
                    'return_rate' => $rate,
                    'estimated_loss' => $returnedAmount,
                    'dominant_reason_group' => $ret->dominant_reason_group ?? null,
                    'confidence_score' => $this->confidenceLabel($unitsSold),
                ],
            );
        }

        $this->updateVariantStats($workspaceId, $dayStart, $dayEnd, $dateString, $productId, $mlItemId, $connectionId);
    }

    private function updateVariantStats(
        int $workspaceId,
        Carbon $dayStart,
        Carbon $dayEnd,
        string $dateString,
        ?int $productId,
        ?string $mlItemId,
        ?int $connectionId,
    ): void {
        $soldQuery = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->whereBetween('orders.ordered_at', [$dayStart, $dayEnd])
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses());

        if ($connectionId !== null && $connectionId > 0) {
            $soldQuery->where('order_lines.connection_id', $connectionId);
        }
        if ($productId !== null) {
            $soldQuery->where('variants.product_id', $productId);
        } elseif (filled($mlItemId)) {
            $soldQuery->where('order_lines.external_item_id', $mlItemId);
        }

        $soldRows = $soldQuery
            ->select([
                'variants.product_id as product_id',
                'order_lines.variant_id',
                'order_lines.external_item_id as ml_item_id',
                'order_lines.external_variation_id as ml_variation_id',
                'order_lines.sku',
                'order_lines.connection_id',
                DB::raw('SUM(order_lines.quantity) as units_sold'),
            ])
            ->groupBy(
                'variants.product_id',
                'order_lines.variant_id',
                'order_lines.external_item_id',
                'order_lines.external_variation_id',
                'order_lines.sku',
                'order_lines.connection_id',
            )
            ->get();

        $returnQuery = ReturnCaseItem::query()
            ->join('returns', 'returns.id', '=', 'return_case_items.return_id')
            ->where('return_case_items.workspace_id', $workspaceId)
            ->whereBetween('returns.opened_at', [$dayStart, $dayEnd]);

        if ($connectionId !== null && $connectionId > 0) {
            $returnQuery->where('returns.connection_id', $connectionId);
        }
        if ($productId !== null) {
            $returnQuery->where('return_case_items.product_id', $productId);
        } elseif (filled($mlItemId)) {
            $returnQuery->where('return_case_items.ml_item_id', $mlItemId);
        }

        $returnRows = $returnQuery
            ->select([
                'return_case_items.product_id',
                'return_case_items.variant_id',
                'return_case_items.ml_item_id',
                'return_case_items.ml_variation_id',
                'return_case_items.variant_label',
                'return_case_items.sku',
                'returns.connection_id',
                DB::raw('SUM(return_case_items.quantity) as returned_units'),
                DB::raw('COUNT(DISTINCT returns.id) as return_count'),
                DB::raw('SUM(return_case_items.line_amount) as returned_amount'),
            ])
            ->groupBy(
                'return_case_items.product_id',
                'return_case_items.variant_id',
                'return_case_items.ml_item_id',
                'return_case_items.ml_variation_id',
                'return_case_items.variant_label',
                'return_case_items.sku',
                'returns.connection_id',
            )
            ->get()
            ->keyBy(fn ($row) => $this->variantKey($row->variant_id, $row->ml_variation_id, $row->ml_item_id));

        $keys = collect($soldRows)
            ->map(fn ($row) => $this->variantKey($row->variant_id, $row->ml_variation_id, $row->ml_item_id))
            ->merge($returnRows->keys())
            ->unique();

        foreach ($keys as $key) {
            $sold = $soldRows->first(
                fn ($row) => $this->variantKey($row->variant_id, $row->ml_variation_id, $row->ml_item_id) === $key
            );
            $ret = $returnRows->get($key);
            $unitsSold = (int) ($sold->units_sold ?? 0);
            $returnedUnits = (int) ($ret->returned_units ?? 0);
            $rate = $unitsSold > 0 ? round($returnedUnits / $unitsSold, 4) : ($returnedUnits > 0 ? 1.0 : 0.0);

            $variantId = $sold->variant_id ?? $ret->variant_id ?? null;
            $existingVariant = ReturnVariantDailyStat::query()
                ->where('workspace_id', $workspaceId)
                ->whereDate('date', $dateString)
                ->where('variant_id', $variantId)
                ->where('ml_variation_id', ($sold->ml_variation_id ?? $ret->ml_variation_id) ?: '')
                ->where('ml_item_id', ($sold->ml_item_id ?? $ret->ml_item_id) ?: '')
                ->first();

            $variantAttrs = [
                'workspace_id' => $workspaceId,
                'date' => $dateString,
                'variant_id' => $variantId,
                'ml_variation_id' => ($sold->ml_variation_id ?? $ret->ml_variation_id) ?: '',
                'ml_item_id' => ($sold->ml_item_id ?? $ret->ml_item_id) ?: '',
                'connection_id' => $sold->connection_id ?? $ret->connection_id ?? $connectionId,
                'product_id' => $sold->product_id ?? $ret->product_id ?? null,
                'variant_label' => $ret->variant_label ?? null,
                'sku' => $sold->sku ?? $ret->sku ?? null,
                'units_sold' => $unitsSold,
                'returned_units' => $returnedUnits,
                'return_count' => (int) ($ret->return_count ?? 0),
                'returned_amount' => (float) ($ret->returned_amount ?? 0),
                'return_rate' => $rate,
            ];

            if ($existingVariant !== null) {
                $existingVariant->fill($variantAttrs)->save();
            } else {
                ReturnVariantDailyStat::query()->create($variantAttrs);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertProductStat(
        int $workspaceId,
        string $dateString,
        ?int $productId,
        string $mlItemId,
        array $attributes,
    ): void {
        $existing = ReturnProductDailyStat::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('date', $dateString)
            ->where('product_id', $productId)
            ->where('ml_item_id', $mlItemId)
            ->first();

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return;
        }

        ReturnProductDailyStat::query()->create([
            'workspace_id' => $workspaceId,
            'date' => $dateString,
            'product_id' => $productId,
            'ml_item_id' => $mlItemId,
            ...$attributes,
        ]);
    }

    private function productKey(mixed $productId, mixed $mlItemId): string
    {
        return ($productId ?: '0').'|'.($mlItemId ?: '');
    }

    private function variantKey(mixed $variantId, mixed $mlVariationId, mixed $mlItemId): string
    {
        return ($variantId ?: '0').'|'.($mlVariationId ?: '').'|'.($mlItemId ?: '');
    }

    private function confidenceLabel(int $unitsSold): string
    {
        $buckets = config('returns.risk_score.confidence_sales_buckets', []);
        if ($unitsSold < (int) ($buckets['low'] ?? 5)) {
            return 'low';
        }
        if ($unitsSold < (int) ($buckets['medium'] ?? 30)) {
            return 'medium';
        }
        if ($unitsSold < (int) ($buckets['high'] ?? 100)) {
            return 'high';
        }

        return 'very_high';
    }
}
