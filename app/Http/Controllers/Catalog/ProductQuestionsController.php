<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ProductQuestionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $productId = $request->filled('product_id') ? (int) $request->input('product_id') : null;
        $mlItemId = $request->filled('ml_item_id')
            ? trim((string) $request->input('ml_item_id'))
            : null;

        abort_unless($productId !== null || filled($mlItemId), 422);

        if ($productId !== null) {
            Product::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($productId)
                ->firstOrFail();
        }

        $connectionIds = null;
        if ($request->filled('connection_id')) {
            $connectionIds = [(int) $request->input('connection_id')];
        }
        $rawIds = $request->input('connection_ids', []);
        if (is_array($rawIds) && $rawIds !== []) {
            $connectionIds = array_values(array_unique(array_map('intval', $rawIds)));
        }

        $externalItemIds = $this->resolveExternalItemIds($workspaceId, $productId, $mlItemId);
        if ($externalItemIds === []) {
            return response()->json([
                'summary' => ['total' => 0, 'unanswered' => 0],
                'channels' => [],
            ]);
        }

        $query = Question::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('external_item_id', $externalItemIds)
            ->with(['connection:id,provider,external_user_id,display_name,color']);

        if ($connectionIds !== null && $connectionIds !== []) {
            $query->whereIn('connection_id', $connectionIds);
        }

        /** @var Collection<int, Question> $questions */
        $questions = $query
            ->orderByRaw("CASE WHEN status IN ('unanswered','closed_unanswered') THEN 0 ELSE 1 END")
            ->orderByDesc('asked_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $grouped = $questions->groupBy(fn (Question $q) => (int) ($q->connection_id ?? 0));

        $channels = $grouped
            ->map(function (Collection $rows, $connectionId) {
                /** @var Question|null $first */
                $first = $rows->first();
                $connection = $first?->connection;

                return [
                    'connection' => $connection ? [
                        'id' => $connection->id,
                        'provider' => $connection->provider,
                        'display_name' => $connection->display_name,
                        'external_user_id' => $connection->external_user_id,
                        'color' => $connection->color,
                    ] : [
                        'id' => (int) $connectionId,
                        'provider' => 'unknown',
                        'display_name' => 'Sin canal',
                        'external_user_id' => null,
                        'color' => null,
                    ],
                    'counts' => [
                        'total' => $rows->count(),
                        'unanswered' => $rows->whereIn('status', ['unanswered', 'closed_unanswered'])->count(),
                    ],
                    'questions' => $rows->map(fn (Question $q) => [
                        'id' => $q->id,
                        'external_question_id' => $q->external_question_id,
                        'external_item_id' => $q->external_item_id,
                        'buyer_external_id' => $q->buyer_external_id,
                        'status' => $q->status,
                        'question_text' => $q->question_text,
                        'answer_text' => $q->answer_text,
                        'asked_at' => $q->asked_at?->toIso8601String(),
                        'answered_at' => $q->answered_at?->toIso8601String(),
                        'meta' => $q->meta,
                    ])->values()->all(),
                ];
            })
            ->sortBy(fn (array $channel) => mb_strtolower(
                (string) ($channel['connection']['display_name']
                    ?: $channel['connection']['external_user_id']
                    ?: $channel['connection']['provider']
                    ?: ''),
            ))
            ->values()
            ->all();

        return response()->json([
            'summary' => [
                'total' => $questions->count(),
                'unanswered' => $questions->whereIn('status', ['unanswered', 'closed_unanswered'])->count(),
            ],
            'channels' => $channels,
        ]);
    }

    /**
     * @return list<string>
     */
    private function resolveExternalItemIds(int $workspaceId, ?int $productId, ?string $mlItemId): array
    {
        $ids = [];

        if (filled($mlItemId)) {
            $ids[] = (string) $mlItemId;
        }

        if ($productId === null) {
            return array_values(array_unique($ids));
        }

        $direct = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->where('product_id', $productId)
            ->whereNotNull('external_item_id')
            ->pluck('external_item_id');

        foreach ($direct as $ext) {
            if (is_string($ext) && $ext !== '') {
                $ids[] = $ext;
            }
        }

        $viaVariants = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('external_item_id')
            ->whereHas(
                'variants',
                fn ($q) => $q->whereHas(
                    'variant',
                    fn ($canonical) => $canonical->where('product_id', $productId),
                ),
            )
            ->pluck('external_item_id');

        foreach ($viaVariants as $ext) {
            if (is_string($ext) && $ext !== '') {
                $ids[] = $ext;
            }
        }

        return array_values(array_unique($ids));
    }
}
