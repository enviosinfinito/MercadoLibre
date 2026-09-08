<?php

namespace App\Http\Controllers\PostSale;

use App\Domain\PostSale\Actions\AnswerCanonicalQuestion;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsWithFilteredSelection;
use App\Http\Controllers\Controller;
use App\Http\Filters\Questions\QuestionFilterRegistry;
use App\Http\Requests\Questions\IndexFilterRequest;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class QuestionsController extends Controller
{
    use RespondsWithFilteredSelection;

    public function index(IndexFilterRequest $request, QuestionFilterRegistry $filterRegistry): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        $query = Question::query()
            ->where('workspace_id', $workspaceId)
            ->with(['connection:id,provider,external_user_id,display_name,color'])
            ->orderByDesc('asked_at')
            ->orderByDesc('id');

        $filterRegistry->apply($query, $filters);

        $questions = $query->paginate(25)->withQueryString();

        $baseCounts = Question::query()->where('workspace_id', $workspaceId);
        if (! empty($filters['connection_id'])) {
            $baseCounts->where('connection_id', (int) $filters['connection_id']);
        }

        $statusCounts = (clone $baseCounts)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('provider')
            ->get(['id', 'provider', 'external_user_id', 'display_name', 'color', 'status']);

        return Inertia::render('Questions/Index', [
            'questions' => $questions,
            'filters' => $filters,
            'status_counts' => [
                'all' => (int) (clone $baseCounts)->count(),
                'unanswered' => (int) ($statusCounts['unanswered'] ?? 0),
                'answered' => (int) ($statusCounts['answered'] ?? 0),
            ],
            'connections' => $connections,
        ]);
    }

    public function allIds(IndexFilterRequest $request, QuestionFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Question::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionAllIds($query);
    }

    public function filteredSums(IndexFilterRequest $request, QuestionFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Question::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionFilteredSums($query, []);
    }

    public function show(Question $question): JsonResponse
    {
        abort_unless((int) $question->workspace_id === TenantContext::id(), 404);

        return response()->json($this->buildShowPayload($question));
    }

    public function answer(Request $request, Question $question, AnswerCanonicalQuestion $answer): JsonResponse
    {
        abort_unless((int) $question->workspace_id === TenantContext::id(), 404);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        try {
            $question = $answer->execute($question, $data['text']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->buildShowPayload($question));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShowPayload(Question $question): array
    {
        $question->load(['connection:id,provider,external_user_id,display_name,site_id,color']);

        $listing = null;
        if ($question->connection_id && $question->external_item_id) {
            $listing = ChannelListing::query()
                ->where('connection_id', $question->connection_id)
                ->where('external_item_id', $question->external_item_id)
                ->first(['id', 'external_item_id', 'title', 'permalink', 'status']);
        }

        return [
            'question' => $question,
            'listing' => $listing,
        ];
    }
}
