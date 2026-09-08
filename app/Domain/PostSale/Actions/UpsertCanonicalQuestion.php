<?php

namespace App\Domain\PostSale\Actions;

use App\Models\Question;
use Illuminate\Support\Carbon;

final class UpsertCanonicalQuestion
{
    /**
     * @param  array{
     *   external_question_id: string,
     *   external_item_id?: string|null,
     *   buyer_external_id?: string|null,
     *   status?: string|null,
     *   question_text?: string|null,
     *   answer_text?: string|null,
     *   asked_at?: Carbon|string|null,
     *   answered_at?: Carbon|string|null,
     *   raw_snapshot_id?: int|null,
     *   meta?: array<string, mixed>|null,
     * }  $payload
     */
    public function execute(int $workspaceId, int $connectionId, array $payload): Question
    {
        $externalId = (string) ($payload['external_question_id'] ?? '');
        if ($externalId === '') {
            throw new \InvalidArgumentException('external_question_id is required.');
        }

        return Question::query()->updateOrCreate(
            [
                'connection_id' => $connectionId,
                'external_question_id' => $externalId,
            ],
            [
                'workspace_id' => $workspaceId,
                'external_item_id' => isset($payload['external_item_id']) && $payload['external_item_id'] !== ''
                    ? (string) $payload['external_item_id']
                    : null,
                'buyer_external_id' => isset($payload['buyer_external_id']) && $payload['buyer_external_id'] !== ''
                    ? (string) $payload['buyer_external_id']
                    : null,
                'status' => $this->mapStatus((string) ($payload['status'] ?? 'unanswered')),
                'question_text' => $payload['question_text'] ?? null,
                'answer_text' => $payload['answer_text'] ?? null,
                'asked_at' => $payload['asked_at'] ?? null,
                'answered_at' => $payload['answered_at'] ?? null,
                'raw_snapshot_id' => $payload['raw_snapshot_id'] ?? null,
                'meta' => $payload['meta'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   external_question_id: string,
     *   external_item_id: string|null,
     *   buyer_external_id: string|null,
     *   status: string,
     *   question_text: string|null,
     *   answer_text: string|null,
     *   asked_at: Carbon|null,
     *   answered_at: Carbon|null,
     *   meta: array<string, mixed>,
     * }
     */
    public function mapFromProviderPayload(array $raw, ?string $fallbackExternalId = null): array
    {
        $answer = is_array($raw['answer'] ?? null) ? $raw['answer'] : null;
        $from = is_array($raw['from'] ?? null) ? $raw['from'] : null;

        $externalId = (string) ($raw['id'] ?? $fallbackExternalId ?? '');
        $status = (string) ($raw['status'] ?? 'UNANSWERED');

        $answerText = null;
        $answeredAt = null;
        if ($answer !== null) {
            $answerText = isset($answer['text']) ? (string) $answer['text'] : null;
            $answeredAt = $this->parseDate($answer['date_created'] ?? null);
        }

        return [
            'external_question_id' => $externalId,
            'external_item_id' => isset($raw['item_id']) ? (string) $raw['item_id'] : null,
            'buyer_external_id' => isset($from['id']) ? (string) $from['id'] : null,
            'status' => $this->mapStatus($status),
            'question_text' => isset($raw['text']) ? (string) $raw['text'] : null,
            'answer_text' => $answerText,
            'asked_at' => $this->parseDate($raw['date_created'] ?? null),
            'answered_at' => $answeredAt,
            'meta' => array_filter([
                'from' => $from,
                'answer' => $answer,
                'seller_id' => $raw['seller_id'] ?? null,
                'hold' => $raw['hold'] ?? null,
                'deleted_from_listing' => $raw['deleted_from_listing'] ?? null,
            ], static fn ($v) => $v !== null),
        ];
    }

    public function mapStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'ANSWERED' => 'answered',
            'CLOSED_UNANSWERED' => 'closed_unanswered',
            'UNDER_REVIEW' => 'under_review',
            'BANNED' => 'banned',
            'DELETED' => 'deleted',
            'UNANSWERED' => 'unanswered',
            default => strtolower($providerStatus) !== '' ? strtolower($providerStatus) : 'unanswered',
        };
    }

    private function parseDate(mixed $value): ?Carbon
    {
        return \App\Domain\Shared\Support\ProviderDateTime::parseUtc($value);
    }
}
