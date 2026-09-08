<?php

namespace App\Domain\Returns\Actions;

final class ReturnReasonRulesEngine
{
    /**
     * @param  array{texts: list<string>, evidence: list<array{source: string, id: int|null, text: string}>, ml_reason: string|null}  $corpus
     * @return array{
     *   reason_group: string,
     *   reason_label: string,
     *   summary: string,
     *   confidence: string,
     *   evidence_quotes: list<string>,
     *   signals: array<string, mixed>
     * }
     */
    public function execute(array $corpus): array
    {
        $collector = app(CollectReturnTextCorpus::class);
        $haystack = $collector->normalize(implode(' ', $corpus['texts']));
        $groups = config('returns.reason_keywords', []);
        $labels = config('returns.reason_groups', []);

        $scores = [];
        $hits = [];
        foreach ($groups as $group => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                $normalizedKeyword = $collector->normalize((string) $keyword);
                if ($normalizedKeyword !== '' && str_contains($haystack, $normalizedKeyword)) {
                    $score += mb_strlen($normalizedKeyword) >= 12 ? 2 : 1;
                    $hits[$group][] = $keyword;
                }
            }
            if ($score > 0) {
                $scores[$group] = $score;
            }
        }

        arsort($scores);
        $topGroup = array_key_first($scores) ?: 'other';
        $topScore = $scores[$topGroup] ?? 0;

        if ($topGroup === 'other' && filled($corpus['ml_reason'])) {
            $mlHaystack = $collector->normalize((string) $corpus['ml_reason']);
            foreach ($groups as $group => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($mlHaystack, $collector->normalize((string) $keyword))) {
                        $topGroup = $group;
                        $topScore = max($topScore, 2);
                        break 2;
                    }
                }
            }
        }

        $confidence = match (true) {
            $topScore >= 4 => 'high',
            $topScore >= 2 => 'medium',
            $topScore >= 1 => 'low',
            default => 'low',
        };

        if ($topGroup === 'other' && filled($corpus['ml_reason']) && $topScore === 0) {
            $confidence = 'medium';
        }

        $quotes = collect($corpus['evidence'] ?? [])
            ->filter(fn ($e) => in_array($e['source'] ?? '', ['problem', 'order_message', 'claim_message'], true))
            ->pluck('text')
            ->filter()
            ->take(2)
            ->values()
            ->all();

        $label = $labels[$topGroup] ?? 'Otros';
        $summary = $this->buildSummary($topGroup, $label, $quotes, $corpus['ml_reason'] ?? null);

        return [
            'reason_group' => $topGroup,
            'reason_label' => $label,
            'summary' => $summary,
            'confidence' => $confidence,
            'evidence_quotes' => $quotes,
            'signals' => [
                'scores' => $scores,
                'hits' => $hits,
                'ml_reason' => $corpus['ml_reason'] ?? null,
            ],
        ];
    }

    /**
     * @param  list<string>  $quotes
     */
    private function buildSummary(string $group, string $label, array $quotes, ?string $mlReason): string
    {
        $parts = ["Motivo probable: {$label}."];
        if ($quotes !== []) {
            $parts[] = 'El comprador mencionó: "'.$this->truncate($quotes[0], 120).'".';
        } elseif (filled($mlReason)) {
            $parts[] = 'Según Mercado Libre: "'.$this->truncate($mlReason, 120).'".';
        } else {
            $parts[] = 'No hay comentarios claros del comprador; se usó la clasificación disponible.';
        }

        return implode(' ', $parts);
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
