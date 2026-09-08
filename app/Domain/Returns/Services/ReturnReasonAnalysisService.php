<?php

namespace App\Domain\Returns\Services;

use App\Models\ReturnCase;
use Illuminate\Support\Carbon;

final class ReturnReasonAnalysisService
{
    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    public function global(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?array $connectionIds = null,
    ): array {
        return $this->aggregate($workspaceId, $start, $end, null, null, $connectionIds);
    }

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
        return $this->aggregate($workspaceId, $start, $end, $productId, $mlItemId, $connectionIds);
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    private function aggregate(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?int $productId,
        ?string $mlItemId,
        ?array $connectionIds,
    ): array {
        $query = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$start, $end]);

        if ($connectionIds) {
            $query->whereIn('connection_id', $connectionIds);
        }
        if ($productId !== null) {
            $query->where('dominant_product_id', $productId);
        } elseif (filled($mlItemId)) {
            $query->where('dominant_ml_item_id', $mlItemId);
        }

        $cases = $query->get([
            'id',
            'inferred_reason_group',
            'reason_group',
            'returned_amount',
            'dominant_product_id',
        ]);

        $total = max(1, $cases->count());
        $labels = config('returns.reason_groups', []);
        $bucket = [];

        foreach ($cases as $case) {
            $group = $case->inferred_reason_group ?: ($case->reason_group ?: 'other');
            if (! isset($bucket[$group])) {
                $bucket[$group] = [
                    'group' => $group,
                    'label' => $labels[$group] ?? $group,
                    'count' => 0,
                    'amount' => 0.0,
                    'products' => [],
                ];
            }
            $bucket[$group]['count']++;
            $bucket[$group]['amount'] += (float) $case->returned_amount;
            if ($case->dominant_product_id) {
                $bucket[$group]['products'][$case->dominant_product_id] = true;
            }
        }

        $rows = [];
        foreach ($bucket as $row) {
            $rows[] = [
                'group' => $row['group'],
                'label' => $row['label'],
                'count' => $row['count'],
                'share' => round($row['count'] / $total, 4),
                'amount' => round($row['amount'], 2),
                'products_affected' => count($row['products']),
            ];
        }

        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $rows;
    }
}
