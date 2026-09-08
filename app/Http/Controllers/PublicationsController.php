<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\RefreshChannelListing;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsForEmbeddedEditor;
use App\Http\Controllers\Concerns\RespondsWithFilteredSelection;
use App\Http\Filters\Publications\PublicationFilterRegistry;
use App\Http\Requests\Publications\IndexFilterRequest;
use App\Jobs\PushOutboundCommandJob;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\OutboundCommand;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class PublicationsController extends Controller
{
    use RespondsForEmbeddedEditor;
    use RespondsWithFilteredSelection;

    public function index(IndexFilterRequest $request, PublicationFilterRegistry $filterRegistry): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();
        $withoutCost = (bool) $filters['without_cost'];

        $query = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'variants.variant:id,sku,name',
                'variants.variant.costLayers:id,variant_id',
                'connection:id,provider,external_user_id,display_name,color',
            ])
            ->orderByDesc('updated_at');

        $filterRegistry->apply($query, $filters);

        $listings = $query->paginate(25)->withQueryString();

        $listings->getCollection()->transform(function (ChannelListing $listing) {
            $matchedSku = $listing->variants
                ->first(fn ($v) => $v->variant_id !== null)
                ?->variant
                ?->sku;

            $listing->setAttribute('matched_sku', $matchedSku);
            $listing->setAttribute('is_matched', $matchedSku !== null);
            $listing->setAttribute('platform', $listing->connection?->provider ?? $listing->provider);
            $listing->setAttribute('missing_cost', $listing->isMissingCost());

            return $listing;
        });

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('provider')
            ->get(['id', 'provider', 'external_user_id', 'display_name', 'color', 'status']);

        return Inertia::render('Publications/Index', [
            'listings' => $listings,
            'connections' => $connections,
            'filters' => $filters,
            'listings_without_cost_count' => ChannelListing::countWithoutCostForWorkspace($workspaceId),
        ]);
    }

    public function allIds(IndexFilterRequest $request, PublicationFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = ChannelListing::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionAllIds($query);
    }

    public function filteredSums(IndexFilterRequest $request, PublicationFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = ChannelListing::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionFilteredSums($query, []);
    }

    public function show(ChannelListing $channelListing): JsonResponse
    {
        abort_unless((int) $channelListing->workspace_id === TenantContext::id(), 404);

        return response()->json($this->buildShowPayload($channelListing));
    }

    public function syncNow(
        Request $request,
        ChannelListing $channelListing,
        RefreshChannelListing $refresh,
    ): JsonResponse|RedirectResponse {
        abort_unless((int) $channelListing->workspace_id === TenantContext::id(), 404);

        try {
            $channelListing = $refresh->execute($channelListing);
        } catch (InvalidArgumentException $e) {
            if (($request->wantsJson() || $request->expectsJson()) && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('publications.index')
                ->with('error', $e->getMessage());
        } catch (RuntimeException $e) {
            if (($request->wantsJson() || $request->expectsJson()) && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            return redirect()
                ->route('publications.index')
                ->with('error', $e->getMessage());
        }

        $payload = $this->buildShowPayload($channelListing);

        if (($request->wantsJson() || $request->expectsJson()) && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return redirect()
            ->route('publications.index')
            ->with('success', 'Publicación actualizada desde Mercado Libre.');
    }

    public function update(Request $request, ChannelListing $channelListing): RedirectResponse|JsonResponse
    {
        abort_unless((int) $channelListing->workspace_id === TenantContext::id(), 404);

        $data = $request->validate([
            'status' => ['nullable', 'in:active,paused'],
            'title' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'available_quantity' => ['nullable', 'integer', 'min:0'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['title'])) {
            $channelListing->title = $data['title'];
        }
        if (isset($data['status'])) {
            $channelListing->status = $data['status'];
        }
        $channelListing->save();

        $workspace = Workspace::query()->find($channelListing->workspace_id);
        $dryRun = array_key_exists('dry_run', $data)
            ? (bool) $data['dry_run']
            : (bool) ($workspace?->outbound_dry_run ?? true);

        $payload = array_filter([
            'external_item_id' => $channelListing->external_item_id,
            'channel_listing_id' => $channelListing->id,
            'status' => $data['status'] ?? null,
            'title' => $data['title'] ?? null,
            'price' => $data['price'] ?? null,
            'available_quantity' => $data['available_quantity'] ?? null,
        ], fn ($v) => $v !== null);

        $commandType = isset($data['status']) ? 'listing_status' : 'listing_update';

        $command = OutboundCommand::query()->create([
            'workspace_id' => $channelListing->workspace_id,
            'connection_id' => $channelListing->connection_id,
            'command_type' => $commandType,
            'status' => 'pending',
            'dry_run' => $dryRun,
            'payload' => $payload,
            'idempotency_key' => 'listing:'.$channelListing->id.':'.Str::uuid()->toString(),
            'available_at' => now(),
        ]);

        PushOutboundCommandJob::dispatch(
            (int) $channelListing->workspace_id,
            (int) $channelListing->connection_id,
            (int) $command->id,
        );

        return $this->respondEmbeddedSave(
            $request,
            'Publicación actualizada y sync encolado.',
            ['listing_id' => $channelListing->id, 'status' => $channelListing->status],
        );
    }

    public function bulk(Request $request): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:pause,activate'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $status = $data['action'] === 'pause' ? 'paused' : 'active';
        $workspace = Workspace::query()->find($workspaceId);
        $dryRun = array_key_exists('dry_run', $data)
            ? (bool) $data['dry_run']
            : (bool) ($workspace?->outbound_dry_run ?? true);

        $listings = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $data['ids'])
            ->get();

        $count = 0;
        foreach ($listings as $listing) {
            $listing->status = $status;
            $listing->save();

            $command = OutboundCommand::query()->create([
                'workspace_id' => $workspaceId,
                'connection_id' => $listing->connection_id,
                'command_type' => 'listing_status',
                'status' => 'pending',
                'dry_run' => $dryRun,
                'payload' => [
                    'external_item_id' => $listing->external_item_id,
                    'channel_listing_id' => $listing->id,
                    'status' => $status,
                ],
                'idempotency_key' => 'listing:bulk:'.$listing->id.':'.Str::uuid()->toString(),
                'available_at' => now(),
            ]);

            PushOutboundCommandJob::dispatch(
                (int) $workspaceId,
                (int) $listing->connection_id,
                (int) $command->id,
            );
            $count++;
        }

        return redirect()
            ->back()
            ->with('success', "{$count} publicación(es) marcadas como {$status}.");
    }

    /**
     * @return array{listing: array<string, mixed>}
     */
    private function buildShowPayload(ChannelListing $channelListing): array
    {
        $channelListing->load([
            'connection:id,provider,external_user_id,display_name,color',
            'variants.variant:id,sku,name,product_id',
        ]);

        return [
            'listing' => [
                'id' => $channelListing->id,
                'title' => $channelListing->title,
                'status' => $channelListing->status,
                'external_item_id' => $channelListing->external_item_id,
                'permalink' => $channelListing->permalink,
                'description' => $channelListing->description,
                'pictures' => $channelListing->pictures ?? [],
                'attributes_meta' => $channelListing->attributes_meta ?? [],
                'purchase_experience' => $channelListing->purchase_experience,
                'purchase_experience_synced_at' => $channelListing->purchase_experience_synced_at?->toIso8601String(),
                'pe_color' => $channelListing->pe_color,
                'pe_value' => $channelListing->pe_value,
                'category_id' => $channelListing->category_id,
                'category_name' => $channelListing->category_name,
                'logistic_type' => $channelListing->logistic_type,
                'provider' => $channelListing->provider,
                'product_id' => $channelListing->product_id,
                'connection' => $channelListing->connection ? [
                    'id' => $channelListing->connection->id,
                    'provider' => $channelListing->connection->provider,
                    'display_name' => $channelListing->connection->display_name,
                    'external_user_id' => $channelListing->connection->external_user_id,
                    'color' => $channelListing->connection->color,
                ] : null,
                'variants' => $channelListing->variants->map(static fn ($v) => [
                    'id' => $v->id,
                    'sku_external' => $v->sku_external,
                    'external_variation_id' => $v->external_variation_id,
                    'status' => $v->status,
                    'price_amount' => $v->price_amount,
                    'currency_code' => $v->currency_code,
                    'available_quantity' => $v->available_quantity,
                    'attribute_combinations' => $v->attribute_combinations,
                    'picture_ids' => $v->picture_ids,
                    'matched' => $v->variant_id !== null,
                    'variant_sku' => $v->variant?->sku,
                    'variant_name' => $v->variant?->name,
                    'product_id' => $v->variant?->product_id,
                ])->values(),
            ],
        ];
    }
}
