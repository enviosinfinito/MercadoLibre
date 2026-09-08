<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Ads\Services\AdsMetricsQuery;
use App\Domain\Ads\Services\AdsProfitabilityService;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ChannelListing;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductAdsController extends Controller
{
    public function __construct(
        private readonly AdsMetricsQuery $adsMetricsQuery,
        private readonly AdsProfitabilityService $profitability,
    ) {}

    public function show(Request $request, Product $product): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $product->workspace_id === (int) $workspaceId, 404);

        $connectionIds = $request->input('connection_ids', []);
        if (! is_array($connectionIds)) {
            $connectionIds = $connectionIds !== null && $connectionIds !== '' ? [$connectionIds] : [];
        }
        $connectionIds = array_values(array_filter(array_map('intval', $connectionIds), fn ($id) => $id > 0));

        $itemIds = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($product): void {
                $q->where('product_id', $product->id)
                    ->orWhereHas(
                        'variants',
                        fn ($v) => $v->whereHas(
                            'variant',
                            fn ($canonical) => $canonical->where('product_id', $product->id),
                        ),
                    );
            })
            ->whereNotNull('external_item_id')
            ->pluck('external_item_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        $metrics = $this->adsMetricsQuery->execute((int) $workspaceId, [
            'period' => $request->input('period', 'last_30_days'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'connection_ids' => $connectionIds,
            'product_ids' => [(int) $product->id],
            'item_ids' => $itemIds,
            'group_by' => $request->input('group_by', 'campaign'),
        ]);

        // Secondary breakdown by item for multi-listing products.
        $byItem = $this->adsMetricsQuery->execute((int) $workspaceId, [
            'period' => $request->input('period', 'last_30_days'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'connection_ids' => $connectionIds,
            'product_ids' => [(int) $product->id],
            'item_ids' => $itemIds,
            'group_by' => 'item',
        ]);

        $card = $this->profitability->scorecard((int) $workspaceId);
        $itemSet = array_fill_keys($itemIds, true);
        $localRows = array_values(array_filter(
            $card['scorecard'],
            static fn (array $row) => isset($itemSet[$row['ml_item_id'] ?? '']),
        ));
        $recommendations = [];
        foreach (array_slice($localRows, 0, 3) as $row) {
            if (($row['status'] ?? '') === 'green') {
                continue;
            }
            $recommendations[] = [
                'ml_item_id' => $row['ml_item_id'],
                'status' => $row['status'],
                'title' => ($row['waste'] ?? false)
                    ? "Pausar {$row['ml_item_id']}: gastó sin ventas atribuidas"
                    : "Revisar {$row['ml_item_id']}: ROAS {$row['roas']}x vs objetivo {$row['target_roas']}x",
                'reason' => ($row['stock_qty'] ?? null) === 0
                    ? 'Sin stock: conviene pausar el anuncio.'
                    : 'Anclado al margen de contribución del catálogo.',
                'target_roas' => $row['target_roas'],
            ];
        }

        return response()->json([
            'product_id' => $product->id,
            'ml_item_ids' => $itemIds,
            'summary' => $metrics['kpis'],
            'period' => $metrics['period'],
            'series' => $metrics['series'],
            'by_campaign' => $metrics['group_by'] === 'campaign' ? $metrics['breakdown'] : [],
            'by_item' => $byItem['breakdown'],
            'has_data' => $metrics['has_data'],
            'recommendations' => $recommendations,
            'suggested_target_roas' => $itemIds !== []
                ? $this->profitability->suggestedTargetRoas((int) $workspaceId, $itemIds[0])
                : $card['catalog_target_roas'],
            'dashboard_url' => route('ads.dashboard', array_filter([
                'period' => $request->input('period', 'last_30_days'),
                'product_ids' => [(int) $product->id],
                'connection_ids' => $connectionIds ?: null,
            ])),
            'assistant_url' => route('ads.assistant'),
        ]);
    }
}
