<?php

namespace App\Http\Controllers\Returns;

use App\Domain\Returns\Services\ReturnCommentClusterService;
use App\Domain\Returns\Services\ReturnProductAnalyticsService;
use App\Domain\Returns\Support\ReturnPeriodResolver;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsForEmbeddedEditor;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ReturnAction;
use App\Models\ReturnActionNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReturnsProductController extends Controller
{
    use RespondsForEmbeddedEditor;

    public function __construct(
        private readonly ReturnPeriodResolver $periodResolver,
        private readonly ReturnProductAnalyticsService $products,
        private readonly ReturnCommentClusterService $clusters,
    ) {}

    public function show(Request $request, string $product): Response|JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $productId = null;
        $mlItemId = null;
        if (str_starts_with($product, 'ml:')) {
            $mlItemId = substr($product, 3);
        } else {
            $productId = (int) $product;
            abort_unless(
                Product::query()->where('workspace_id', $workspaceId)->whereKey($productId)->exists(),
                404,
            );
        }

        $period = $this->periodResolver->resolve(
            $request->string('period')->toString() ?: 'last_30_days',
            $request->input('from'),
            $request->input('to'),
        );

        $connectionIds = null;
        if ($request->filled('connection_id')) {
            $connectionIds = [(int) $request->input('connection_id')];
        }
        $rawIds = $request->input('connection_ids', []);
        if (is_array($rawIds) && $rawIds !== []) {
            $connectionIds = array_values(array_unique(array_map('intval', $rawIds)));
        }

        $detail = $this->products->productDetail(
            $workspaceId,
            $productId,
            $mlItemId,
            $period['start'],
            $period['end'],
            $period['previous_start'],
            $period['previous_end'],
            $connectionIds,
        );

        $action = ReturnAction::query()
            ->where('workspace_id', $workspaceId)
            ->when($productId, fn ($q) => $q->where('product_id', $productId), fn ($q) => $q->where('ml_item_id', $mlItemId))
            ->with(['notesHistory.user:id,name', 'assignedUser:id,name'])
            ->latest('id')
            ->first();

        $clusters = $this->clusters->clusterForProduct(
            $workspaceId,
            $productId,
            $mlItemId,
            $period['start'],
            $period['end'],
        );

        $payload = [
            'period' => [
                'preset' => $request->input('period', 'last_30_days'),
                'from' => $period['start']->toDateString(),
                'to' => $period['end']->toDateString(),
                'label' => $period['label'],
            ],
            'product_key' => $product,
            'detail' => $detail,
            'action' => $action,
            'clusters' => $clusters,
            'action_statuses' => ReturnAction::STATUSES,
        ];

        if ($this->isEmbeddedRequest($request)) {
            return response()->json($payload);
        }

        return Inertia::render('Returns/ProductShow', $payload);
    }

    public function upsertAction(Request $request, string $product): RedirectResponse|JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $data = $request->validate([
            'status' => 'required|string|in:'.implode(',', ReturnAction::STATUSES),
            'notes' => 'nullable|string|max:5000',
            'action_taken' => 'nullable|string|max:5000',
            'assigned_user_id' => 'nullable|integer',
        ]);

        $productId = null;
        $mlItemId = null;
        if (str_starts_with($product, 'ml:')) {
            $mlItemId = substr($product, 3);
        } else {
            $productId = (int) $product;
        }

        $action = ReturnAction::query()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'product_id' => $productId,
                'ml_item_id' => $mlItemId ?: '',
            ],
            [
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'action_taken' => $data['action_taken'] ?? null,
                'assigned_user_id' => $data['assigned_user_id'] ?? null,
                'status_changed_at' => now(),
            ],
        );

        if (filled($data['notes'] ?? null)) {
            ReturnActionNote::query()->create([
                'workspace_id' => $workspaceId,
                'return_action_id' => $action->id,
                'user_id' => $request->user()?->id,
                'body' => (string) $data['notes'],
                'event_type' => 'note',
            ]);
        }

        ReturnActionNote::query()->create([
            'workspace_id' => $workspaceId,
            'return_action_id' => $action->id,
            'user_id' => $request->user()?->id,
            'body' => 'Estado: '.$data['status'],
            'event_type' => 'status_change',
            'meta' => ['status' => $data['status']],
        ]);

        $action->load(['notesHistory.user:id,name', 'assignedUser:id,name']);

        return $this->respondEmbeddedSave($request, 'Seguimiento guardado', [
            'action' => $action,
        ]);
    }
}
