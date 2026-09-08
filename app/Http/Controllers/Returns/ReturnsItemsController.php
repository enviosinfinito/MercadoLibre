<?php

namespace App\Http\Controllers\Returns;

use App\Domain\Inventory\Actions\ResolveFullCommerceLinks;
use App\Domain\Inventory\Support\FullStockOperationType;
use App\Domain\Returns\Services\ReturnFinancialImpactService;
use App\Domain\Returns\Support\ReturnPeriodResolver;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\FullStockOperation;
use App\Models\ReturnCase;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReturnsItemsController extends Controller
{
    public function __construct(
        private readonly ReturnPeriodResolver $periodResolver,
        private readonly ReturnFinancialImpactService $financialImpact,
        private readonly ResolveFullCommerceLinks $resolveFullLinks,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $period = $this->periodResolver->resolve(
            $request->string('period')->toString() ?: 'last_30_days',
            $request->input('from'),
            $request->input('to'),
        );

        $query = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$period['start'], $period['end']])
            ->with([
                'order:id,external_order_id,pack_id,buyer_external_id',
                'order.pack:id,external_pack_id',
                'items:id,return_id,sku,title,variant_label,quantity,ml_item_id',
                'dominantProduct:id,name',
                'messageAnalysis:id,return_id,source,confidence',
                'connection:id,provider,display_name,external_user_id,color',
            ])
            ->orderByDesc('opened_at');

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderBy('display_name')
            ->get(['id', 'provider', 'external_user_id', 'display_name', 'color', 'status']);

        if ($request->filled('connection_id')) {
            $query->where('connection_id', (int) $request->input('connection_id'));
        }
        $rawIds = $request->input('connection_ids', []);
        if (is_array($rawIds) && $rawIds !== []) {
            $query->whereIn('connection_id', array_map('intval', $rawIds));
        }
        if ($request->filled('outcome')) {
            $query->where('outcome', $request->input('outcome'));
        }
        if ($request->filled('reason_group')) {
            $query->where(function ($q) use ($request): void {
                $q->where('inferred_reason_group', $request->input('reason_group'))
                    ->orWhere('reason_group', $request->input('reason_group'));
            });
        }
        if ($request->filled('product_id')) {
            $query->where('dominant_product_id', (int) $request->input('product_id'));
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q')->toString().'%';
            $query->where(function ($inner) use ($q): void {
                $inner->where('dominant_ml_item_id', 'like', $q)
                    ->orWhere('analysis_summary', 'like', $q)
                    ->orWhere('buyer_comment', 'like', $q)
                    ->orWhereHas('order', fn ($o) => $o->where('external_order_id', 'like', $q))
                    ->orWhereHas('items', fn ($i) => $i->where('sku', 'like', $q)->orWhere('title', 'like', $q));
            });
        }

        $items = $query->paginate(25)->withQueryString()->through(function (ReturnCase $case): array {
            $item = $case->items->first();

            return [
                'id' => $case->id,
                'opened_at' => $case->opened_at,
                'ordered_at' => $case->ordered_at,
                'delivered_at' => $case->delivered_at,
                'days_to_return' => $case->days_to_return,
                'outcome' => $case->outcome,
                'order_id' => $case->order_id,
                'external_order_id' => $case->order?->external_order_id,
                'pack_id' => $case->order?->pack?->external_pack_id,
                'product_id' => $case->dominant_product_id,
                'product_name' => $case->dominantProduct?->name ?: ($item?->title),
                'sku' => $item?->sku,
                'variant_label' => $item?->variant_label,
                'ml_item_id' => $case->dominant_ml_item_id,
                'quantity' => $case->items->sum('quantity'),
                'returned_amount' => (float) $case->returned_amount,
                'reason_label' => $case->inferred_reason_label ?: $case->reason_label,
                'reason_group' => $case->inferred_reason_group ?: $case->reason_group,
                'status' => $case->status,
                'analysis_summary' => $case->analysis_summary,
                'analysis_source' => $case->analysis_source,
                'buyer_comment' => $case->buyer_comment,
                'connection' => $case->connection ? [
                    'id' => $case->connection->id,
                    'provider' => $case->connection->provider,
                    'display_name' => $case->connection->display_name,
                    'external_user_id' => $case->connection->external_user_id,
                    'color' => $case->connection->color,
                ] : null,
            ];
        });

        return Inertia::render('Returns/Items/Index', [
            'period' => [
                'preset' => $request->input('period', 'last_30_days'),
                'from' => $period['start']->toDateString(),
                'to' => $period['end']->toDateString(),
                'label' => $period['label'],
            ],
            'filters' => [
                'q' => $request->input('q', ''),
                'period' => $request->input('period', 'last_30_days'),
                'outcome' => $request->input('outcome', ''),
                'reason_group' => $request->input('reason_group', ''),
                'connection_id' => $request->input('connection_id'),
                'connection_ids' => $request->input('connection_ids', []),
                'product_id' => $request->input('product_id'),
            ],
            'connections' => $connections,
            'items' => $items,
        ]);
    }

    public function show(ReturnCase $returnCase): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null && (int) $returnCase->workspace_id === $workspaceId, 403);

        $returnCase->load([
            'order.lines',
            'order.pack',
            'claim.messages',
            'items.variant',
            'items.product',
            'messageAnalysis',
            'financialImpact',
            'dominantProduct',
            'connection:id,provider,display_name,external_user_id,color',
        ]);

        if ($returnCase->financialImpact === null) {
            $this->financialImpact->compute($returnCase);
            $returnCase->load('financialImpact');
        }

        $commerceIds = [];
        if ($returnCase->order?->external_order_id) {
            $commerceIds[] = (string) $returnCase->order->external_order_id;
        }
        if ($returnCase->order_id) {
            $shipmentIds = Shipment::query()
                ->where('workspace_id', $workspaceId)
                ->where('order_id', $returnCase->order_id)
                ->pluck('external_shipment_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->all();
            $commerceIds = array_merge($commerceIds, $shipmentIds);
        }

        $fullOps = $this->resolveFullLinks->findOperationsForCommerceIds(
            (int) $workspaceId,
            $returnCase->connection_id !== null ? (int) $returnCase->connection_id : null,
            $commerceIds,
        );

        return Inertia::render('Returns/Items/Show', [
            'item' => [
                'id' => $returnCase->id,
                'outcome' => $returnCase->outcome,
                'status' => $returnCase->status,
                'opened_at' => $returnCase->opened_at,
                'closed_at' => $returnCase->closed_at,
                'ordered_at' => $returnCase->ordered_at,
                'delivered_at' => $returnCase->delivered_at,
                'days_to_return' => $returnCase->days_to_return,
                'returned_amount' => (float) $returnCase->returned_amount,
                'estimated_loss' => (float) ($returnCase->estimated_loss ?? 0),
                'currency_code' => $returnCase->currency_code,
                'reason_label' => $returnCase->inferred_reason_label ?: $returnCase->reason_label,
                'reason_group' => $returnCase->inferred_reason_group ?: $returnCase->reason_group,
                'buyer_comment' => $returnCase->buyer_comment,
                'analysis_summary' => $returnCase->analysis_summary,
                'analysis_source' => $returnCase->analysis_source,
                'analysis_confidence' => $returnCase->analysis_confidence,
                'order' => $returnCase->order,
                'claim' => $returnCase->claim,
                'items' => $returnCase->items,
                'message_analysis' => $returnCase->messageAnalysis,
                'financial_impact' => $returnCase->financialImpact,
                'product' => $returnCase->dominantProduct,
                'connection' => $returnCase->connection ? [
                    'id' => $returnCase->connection->id,
                    'provider' => $returnCase->connection->provider,
                    'display_name' => $returnCase->connection->display_name,
                    'external_user_id' => $returnCase->connection->external_user_id,
                    'color' => $returnCase->connection->color,
                ] : null,
                'full_operations' => $fullOps->map(function (FullStockOperation $op) {
                    return [
                        'id' => $op->id,
                        'operation_type' => $op->operation_type,
                        'operation_type_label' => FullStockOperationType::label((string) $op->operation_type),
                        'occurred_at' => optional($op->occurred_at)?->toIso8601String(),
                        'inventory_id' => $op->inventory_id,
                        'available_quantity_delta' => $op->available_quantity_delta !== null
                            ? (string) $op->available_quantity_delta
                            : null,
                        'not_available_quantity_delta' => $op->not_available_quantity_delta !== null
                            ? (string) $op->not_available_quantity_delta
                            : null,
                        'result_available' => $op->result_available !== null
                            ? (string) $op->result_available
                            : null,
                        'result_not_available' => $op->result_not_available !== null
                            ? (string) $op->result_not_available
                            : null,
                        'external_references' => $op->external_references ?? [],
                    ];
                })->values()->all(),
            ],
        ]);
    }
}
