<?php

namespace App\Domain\Returns\Actions;

use App\Models\ClaimMessage;
use App\Models\OrderMessage;
use App\Models\ReturnCase;

final class CollectReturnTextCorpus
{
    /**
     * @return array{
     *   texts: list<string>,
     *   evidence: list<array{source: string, id: int|null, text: string}>,
     *   ml_reason: string|null,
     *   hash: string,
     *   fingerprint: string
     * }
     */
    public function execute(ReturnCase $returnCase): array
    {
        $returnCase->loadMissing(['claim', 'order']);
        $evidence = [];
        $texts = [];

        $claim = $returnCase->claim;
        if ($claim !== null) {
            foreach ([
                ['ml_reason', $claim->reason_detail ?: $claim->reason],
                ['problem', $claim->problem],
            ] as [$source, $text]) {
                if (is_string($text) && trim($text) !== '') {
                    $texts[] = $text;
                    $evidence[] = ['source' => $source, 'id' => $claim->id, 'text' => $text];
                }
            }
        }

        if ($returnCase->order_id !== null) {
            $messages = OrderMessage::query()
                ->where('order_id', $returnCase->order_id)
                ->where('direction', 'inbound')
                ->orderByDesc('sent_at')
                ->limit(30)
                ->get(['id', 'text', 'sent_at']);

            foreach ($messages as $message) {
                $text = trim((string) $message->text);
                if ($text === '') {
                    continue;
                }
                $texts[] = $text;
                $evidence[] = ['source' => 'order_message', 'id' => $message->id, 'text' => $text];
            }
        }

        if ($claim !== null) {
            $claimMessages = ClaimMessage::query()
                ->where('claim_id', $claim->id)
                ->where(function ($q): void {
                    $q->where('sender_role', 'complainant')
                        ->orWhere('sender_role', 'mediator');
                })
                ->orderByDesc('sent_at')
                ->limit(20)
                ->get(['id', 'message', 'sender_role']);

            foreach ($claimMessages as $message) {
                $text = trim((string) $message->message);
                if ($text === '') {
                    continue;
                }
                $texts[] = $text;
                $evidence[] = ['source' => 'claim_message', 'id' => $message->id, 'text' => $text];
            }
        }

        $normalized = collect($texts)
            ->map(fn (string $t) => $this->normalize($t))
            ->filter()
            ->implode("\n");

        $hash = hash('sha256', $normalized);
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        $top = collect($tokens)->filter(fn ($t) => mb_strlen($t) > 3)->take(40)->implode(' ');
        $fingerprint = hash('sha256', $top);

        return [
            'texts' => $texts,
            'evidence' => $evidence,
            'ml_reason' => $claim?->reason_detail ?: $claim?->reason,
            'hash' => $hash,
            'fingerprint' => $fingerprint,
        ];
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

        return $value;
    }
}
