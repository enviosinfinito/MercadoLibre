<?php

namespace App\Http\Controllers\PostSale;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsWithFilteredSelection;
use App\Http\Controllers\Controller;
use App\Http\Filters\Claims\ClaimFilterRegistry;
use App\Http\Requests\Claims\IndexFilterRequest;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsController extends Controller
{
    use RespondsWithFilteredSelection;

    public function index(IndexFilterRequest $request, ClaimFilterRegistry $filterRegistry): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        $query = Claim::query()
            ->where('workspace_id', $workspaceId)
            ->with(['order:id,external_order_id,status'])
            ->orderByDesc('id');

        $filterRegistry->apply($query, $filters);

        $claims = $query
            ->paginate(25)
            ->withQueryString()
            ->through(static function (Claim $claim): array {
                return [
                    'id' => $claim->id,
                    'external_claim_id' => $claim->external_claim_id,
                    'type' => $claim->type,
                    'stage' => $claim->stage,
                    'status' => $claim->status,
                    'reason' => $claim->reason,
                    'resolution_reason' => $claim->resolutionReason(),
                    'order_id' => $claim->order_id,
                    'opened_at' => $claim->opened_at,
                    'order' => $claim->order,
                ];
            });

        return Inertia::render('Claims/Index', [
            'claims' => $claims,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    public function allIds(IndexFilterRequest $request, ClaimFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Claim::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionAllIds($query);
    }

    public function filteredSums(IndexFilterRequest $request, ClaimFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Claim::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionFilteredSums($query, []);
    }
}
