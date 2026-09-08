<?php

namespace App\Domain\Returns\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReturnReasonAiClient
{
    /**
     * @param  list<string>  $texts
     * @return array{
     *   reason_group: string,
     *   reason_label: string,
     *   summary: string,
     *   evidence_quotes: list<string>,
     *   confidence: string,
     *   model: string|null,
     *   prompt_tokens: int,
     *   completion_tokens: int
     * }|null
     */
    public function analyze(array $texts, ?string $mlReason = null): ?array
    {
        if (! config('returns.ai.enabled')) {
            return null;
        }

        $apiKey = config('returns.ai.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            return null;
        }

        $maxChars = (int) config('returns.ai.max_corpus_chars', 1500);
        $corpus = mb_substr(implode("\n---\n", $texts), 0, $maxChars);
        $groups = implode(', ', array_keys(config('returns.reason_groups', [])));
        $model = (string) config('returns.ai.model', 'gpt-4o-mini');

        $system = 'Eres un analista de devoluciones de Mercado Libre. '
            .'Responde SOLO JSON válido con keys: reason_group, reason_label, summary, evidence_quotes, confidence. '
            ."reason_group debe ser uno de: {$groups}. summary máximo 2 frases en español.";

        $user = "Motivo ML: ".($mlReason ?: 'n/d')."\n\nMensajes:\n{$corpus}";

        try {
            $response = Http::baseUrl((string) config('returns.ai.base_url'))
                ->withToken($apiKey)
                ->timeout((int) config('returns.ai.timeout_seconds', 15))
                ->acceptJson()
                ->post('/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.1,
                    'max_tokens' => (int) config('returns.ai.max_completion_tokens', 120),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('returns.ai.http_failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return null;
            }

            $payload = $response->json();
            $content = $payload['choices'][0]['message']['content'] ?? null;
            if (! is_string($content) || $content === '') {
                return null;
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return null;
            }

            $group = (string) ($decoded['reason_group'] ?? 'other');
            $labels = config('returns.reason_groups', []);
            if (! array_key_exists($group, $labels)) {
                $group = 'other';
            }

            return [
                'reason_group' => $group,
                'reason_label' => (string) ($decoded['reason_label'] ?? ($labels[$group] ?? 'Otros')),
                'summary' => (string) ($decoded['summary'] ?? ''),
                'evidence_quotes' => array_values(array_filter(
                    is_array($decoded['evidence_quotes'] ?? null) ? $decoded['evidence_quotes'] : [],
                    fn ($q) => is_string($q) && $q !== '',
                )),
                'confidence' => in_array($decoded['confidence'] ?? '', ['high', 'medium', 'low'], true)
                    ? (string) $decoded['confidence']
                    : 'medium',
                'model' => $model,
                'prompt_tokens' => (int) ($payload['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($payload['usage']['completion_tokens'] ?? 0),
            ];
        } catch (Throwable $e) {
            Log::warning('returns.ai.exception', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }
}
