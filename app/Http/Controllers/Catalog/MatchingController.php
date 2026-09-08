<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Actions\MatchListingToVariant;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ChannelListingVariant;
use App\Models\Variant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MatchingController extends Controller
{
    public function index(): Response
    {
        $workspaceId = TenantContext::id();

        $unmatched = ChannelListingVariant::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('variant_id')
            ->with('listing')
            ->orderByDesc('id')
            ->paginate(25);

        $variants = Variant::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('sku')
            ->get(['id', 'sku', 'name']);

        return Inertia::render('Matching/Index', [
            'unmatched' => $unmatched,
            'variants' => $variants,
        ]);
    }

    public function store(Request $request, MatchListingToVariant $match): RedirectResponse
    {
        $data = $request->validate([
            'channel_listing_variant_id' => ['required', 'integer'],
            'variant_id' => ['required', 'integer'],
        ]);

        $listingVariant = ChannelListingVariant::query()
            ->where('workspace_id', TenantContext::id())
            ->whereKey($data['channel_listing_variant_id'])
            ->firstOrFail();

        $match->execute($listingVariant, variantId: (int) $data['variant_id']);

        return redirect()->back()->with('success', 'Listing matched to variant.');
    }
}
