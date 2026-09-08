<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\UpsertChannelPrice;
use App\Domain\Shared\Support\TenantContext;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\Variant;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PricesController extends Controller
{
    public function index(): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $workspace = Workspace::query()->findOrFail($workspaceId);

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('provider')
            ->get(['id', 'provider', 'external_user_id', 'status']);

        $variants = Variant::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'product:id,name',
                'channelListingVariants.listing:id,connection_id,provider,external_item_id,title,status',
            ])
            ->orderBy('sku')
            ->paginate(50);

        $rows = $variants->getCollection()->map(function (Variant $variant) {
            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name ?? $variant->product?->name,
                'base_price_amount' => $variant->base_price_amount,
                'base_price_currency' => $variant->base_price_currency,
                'channels' => $variant->channelListingVariants->map(function (ChannelListingVariant $clv) use ($variant) {
                    $base = $variant->base_price_amount;
                    $delta = null;
                    if ($base !== null && (float) $base > 0 && $clv->price_amount !== null) {
                        $delta = round((((float) $clv->price_amount - (float) $base) / (float) $base) * 100, 2);
                    }

                    return [
                        'id' => $clv->id,
                        'connection_id' => $clv->listing?->connection_id,
                        'provider' => $clv->listing?->provider,
                        'external_item_id' => $clv->listing?->external_item_id,
                        'price_amount' => $clv->price_amount,
                        'currency_code' => $clv->currency_code,
                        'markup_pct' => $clv->markup_pct,
                        'delta_pct' => $delta,
                        'price_synced_at' => $clv->price_synced_at,
                    ];
                })->values(),
            ];
        });

        $variants->setCollection($rows);

        return Inertia::render('Prices/Index', [
            'rows' => $variants,
            'connections' => $connections,
            'outbound_dry_run' => (bool) $workspace->outbound_dry_run,
        ]);
    }

    public function update(
        Request $request,
        ChannelListingVariant $channelListingVariant,
        UpsertChannelPrice $upsert,
    ): RedirectResponse {
        abort_unless((int) $channelListingVariant->workspace_id === TenantContext::id(), 404);

        $data = $request->validate([
            'price_amount' => ['nullable', 'numeric', 'min:0'],
            'markup_pct' => ['nullable', 'numeric'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'sync' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $upsert->execute($channelListingVariant, $data);

        return redirect()
            ->back()
            ->with('success', 'Precio actualizado'.(($data['sync'] ?? true) ? ' y sync encolado.' : '.'));
    }
}
