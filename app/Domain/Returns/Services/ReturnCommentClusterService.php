<?php

namespace App\Domain\Returns\Services;

use App\Domain\Returns\Actions\CollectReturnTextCorpus;
use App\Models\ReturnCase;
use App\Models\ReturnCommentCluster;
use Illuminate\Support\Carbon;

/**
 * Phase 3 stub: keyword/shingle clustering (local). Architecture ready for embeddings/AI batch later.
 */
final class ReturnCommentClusterService
{
    public function __construct(
        private readonly CollectReturnTextCorpus $corpus,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function clusterForProduct(
        int $workspaceId,
        ?int $productId,
        ?string $mlItemId,
        Carbon $start,
        Carbon $end,
    ): array {
        $cases = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$start, $end])
            ->when($productId, fn ($q) => $q->where('dominant_product_id', $productId), fn ($q) => $q->where('dominant_ml_item_id', $mlItemId))
            ->get();

        $clusters = [];

        foreach ($cases as $case) {
            $text = $case->buyer_comment ?: $case->analysis_summary;
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            $normalized = $this->corpus->normalize($text);
            $tokens = array_values(array_filter(
                preg_split('/\s+/', $normalized) ?: [],
                fn ($t) => mb_strlen($t) > 3,
            ));
            $shingle = implode(' ', array_slice($tokens, 0, 6));
            if ($shingle === '') {
                continue;
            }
            $key = hash('sha256', $shingle);
            if (! isset($clusters[$key])) {
                $clusters[$key] = [
                    'cluster_key' => $key,
                    'label' => mb_substr($shingle, 0, 60),
                    'representative_phrase' => $this->truncate($text, 120),
                    'occurrence_count' => 0,
                    'reason_group' => $case->inferred_reason_group ?: $case->reason_group,
                    'sample_return_ids' => [],
                ];
            }
            $clusters[$key]['occurrence_count']++;
            if (count($clusters[$key]['sample_return_ids']) < 5) {
                $clusters[$key]['sample_return_ids'][] = $case->id;
            }
        }

        usort($clusters, fn ($a, $b) => $b['occurrence_count'] <=> $a['occurrence_count']);
        $top = array_slice(array_values($clusters), 0, 20);

        foreach ($top as $cluster) {
            ReturnCommentCluster::query()->updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'product_id' => $productId,
                    'ml_item_id' => $mlItemId ?: '',
                    'cluster_key' => $cluster['cluster_key'],
                ],
                [
                    'label' => $cluster['label'],
                    'representative_phrase' => $cluster['representative_phrase'],
                    'occurrence_count' => $cluster['occurrence_count'],
                    'reason_group' => $cluster['reason_group'],
                    'sample_return_ids' => $cluster['sample_return_ids'],
                    'meta' => ['method' => 'shingle_v1'],
                ],
            );
        }

        return $top;
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
