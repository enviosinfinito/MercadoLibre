<?php

namespace App\Domain\Returns\Actions;

use App\Jobs\Returns\AggregateProductReturnNarrativeJob;
use App\Models\ReturnCase;
use App\Models\ReturnMessageAnalysis;

final class AnalyzeReturnMessagesHybrid
{
    public function __construct(
        private readonly CollectReturnTextCorpus $collectCorpus,
        private readonly ReturnReasonRulesEngine $rulesEngine,
        private readonly ReturnReasonAiClient $aiClient,
    ) {}

    public function execute(ReturnCase $returnCase, bool $force = false): ReturnMessageAnalysis
    {
        $corpus = $this->collectCorpus->execute($returnCase);

        if (! $force
            && $returnCase->analysis_content_hash === $corpus['hash']
            && $returnCase->messageAnalysis !== null
        ) {
            return $returnCase->messageAnalysis;
        }

        $rules = $this->rulesEngine->execute($corpus);
        $threshold = (string) config('returns.ai.confidence_threshold', 'medium');
        $needsAi = $this->confidenceRank($rules['confidence']) < $this->confidenceRank($threshold)
            || ($rules['reason_group'] === 'other' && count($corpus['texts']) > 0);

        $source = 'rules';
        $result = $rules;
        $model = null;
        $promptTokens = 0;
        $completionTokens = 0;

        if (! $needsAi && $rules['reason_group'] !== 'other') {
            $source = filled($corpus['ml_reason']) && $rules['confidence'] === 'high'
                ? 'rules'
                : 'rules';
        } elseif (! $needsAi && filled($corpus['ml_reason'])) {
            $source = 'ml_reason_only';
        }

        if ($needsAi) {
            $dedup = ReturnMessageAnalysis::query()
                ->where('workspace_id', $returnCase->workspace_id)
                ->where('fingerprint', $corpus['fingerprint'])
                ->where('return_id', '!=', $returnCase->id)
                ->whereNotNull('summary')
                ->orderByDesc('id')
                ->first();

            if ($dedup !== null) {
                $source = 'dedup';
                $result = [
                    'reason_group' => $dedup->reason_group ?? $rules['reason_group'],
                    'reason_label' => $dedup->reason_label ?? $rules['reason_label'],
                    'summary' => $dedup->summary ?? $rules['summary'],
                    'confidence' => $dedup->confidence ?? $rules['confidence'],
                    'evidence_quotes' => $rules['evidence_quotes'],
                    'signals' => array_merge($rules['signals'], ['dedup_from' => $dedup->id]),
                ];
            } else {
                $ai = $this->aiClient->analyze($corpus['texts'], $corpus['ml_reason']);
                if ($ai !== null) {
                    $source = 'hybrid';
                    $result = [
                        'reason_group' => $ai['reason_group'],
                        'reason_label' => $ai['reason_label'],
                        'summary' => $ai['summary'] !== '' ? $ai['summary'] : $rules['summary'],
                        'confidence' => $ai['confidence'],
                        'evidence_quotes' => $ai['evidence_quotes'] !== [] ? $ai['evidence_quotes'] : $rules['evidence_quotes'],
                        'signals' => array_merge($rules['signals'], ['ai' => true]),
                    ];
                    $model = $ai['model'];
                    $promptTokens = $ai['prompt_tokens'];
                    $completionTokens = $ai['completion_tokens'];
                }
            }
        }

        $analysis = ReturnMessageAnalysis::query()->updateOrCreate(
            ['return_id' => $returnCase->id],
            [
                'workspace_id' => $returnCase->workspace_id,
                'corpus_hash' => $corpus['hash'],
                'fingerprint' => $corpus['fingerprint'],
                'source' => $source,
                'reason_group' => $result['reason_group'],
                'reason_label' => $result['reason_label'],
                'summary' => $result['summary'],
                'evidence' => [
                    'quotes' => $result['evidence_quotes'] ?? [],
                    'items' => array_slice($corpus['evidence'], 0, 8),
                ],
                'signals' => $result['signals'] ?? [],
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'confidence' => $result['confidence'],
            ],
        );

        $returnCase->forceFill([
            'inferred_reason_group' => $result['reason_group'],
            'inferred_reason_label' => $result['reason_label'],
            'analysis_summary' => $result['summary'],
            'analysis_confidence' => $result['confidence'],
            'analysis_source' => $source,
            'analysis_content_hash' => $corpus['hash'],
            'analyzed_at' => now(),
            'reason_group' => $returnCase->reason_group ?: $result['reason_group'],
        ])->save();

        AggregateProductReturnNarrativeJob::dispatch(
            (int) $returnCase->workspace_id,
            (int) ($returnCase->connection_id ?? 0),
            $returnCase->dominant_product_id,
            $returnCase->dominant_ml_item_id,
            'last_30_days',
        );

        return $analysis;
    }

    private function confidenceRank(string $level): int
    {
        return match ($level) {
            'high', 'very_high' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}
