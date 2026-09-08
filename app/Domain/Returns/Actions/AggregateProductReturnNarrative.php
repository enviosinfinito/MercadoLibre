<?php

namespace App\Domain\Returns\Actions;

use App\Domain\Returns\Support\ReturnPeriodResolver;
use App\Models\ReturnCase;
use App\Models\ReturnProductNarrative;

final class AggregateProductReturnNarrative
{
    public function __construct(
        private readonly ReturnPeriodResolver $periodResolver,
    ) {}

    public function execute(
        int $workspaceId,
        ?int $productId,
        ?string $mlItemId,
        string $periodPreset = 'last_30_days',
    ): ?ReturnProductNarrative {
        if ($productId === null && blank($mlItemId)) {
            return null;
        }

        $period = $this->periodResolver->resolve($periodPreset);
        $periodKey = $period['key'].':'.$period['end']->toDateString();

        $query = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$period['start'], $period['end']]);

        if ($productId !== null) {
            $query->where('dominant_product_id', $productId);
        } else {
            $query->where('dominant_ml_item_id', $mlItemId);
        }

        $cases = $query->get([
            'id',
            'inferred_reason_group',
            'reason_group',
            'analysis_summary',
            'buyer_comment',
        ]);

        if ($cases->isEmpty()) {
            return null;
        }

        $breakdown = [];
        $phrases = [];
        foreach ($cases as $case) {
            $group = $case->inferred_reason_group ?: ($case->reason_group ?: 'other');
            $breakdown[$group] = ($breakdown[$group] ?? 0) + 1;

            $phrase = $case->buyer_comment ?: $case->analysis_summary;
            if (is_string($phrase) && trim($phrase) !== '') {
                $key = mb_strtolower(mb_substr(trim($phrase), 0, 80));
                $phrases[$key] = [
                    'text' => $this->truncate($phrase, 100),
                    'count' => ($phrases[$key]['count'] ?? 0) + 1,
                ];
            }
        }

        arsort($breakdown);
        $total = $cases->count();
        $labels = config('returns.reason_groups', []);
        $parts = [];
        foreach (array_slice($breakdown, 0, 3, true) as $group => $count) {
            $pct = round(($count / $total) * 100);
            $parts[] = "{$pct}% ".($labels[$group] ?? $group);
        }

        uasort($phrases, fn ($a, $b) => $b['count'] <=> $a['count']);
        $topPhrases = array_values(array_slice($phrases, 0, 5));

        $summary = "{$total} devoluciones en {$period['label']}. ".implode('; ', $parts).'.';
        if ($topPhrases !== []) {
            $summary .= ' Frases frecuentes: '
                .collect($topPhrases)->take(3)->pluck('text')->map(fn ($t) => "\"{$t}\"")->implode(', ')
                .'.';
        }

        $contentHash = hash('sha256', json_encode([$breakdown, $topPhrases], JSON_THROW_ON_ERROR));

        return ReturnProductNarrative::query()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'product_id' => $productId,
                'ml_item_id' => $mlItemId ?: '',
                'period_key' => $periodKey,
            ],
            [
                'summary' => $summary,
                'reason_breakdown' => $breakdown,
                'top_phrases' => $topPhrases,
                'source' => 'rules',
                'content_hash' => $contentHash,
                'cases_analyzed' => $total,
                'ai_calls_used' => 0,
            ],
        );
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
