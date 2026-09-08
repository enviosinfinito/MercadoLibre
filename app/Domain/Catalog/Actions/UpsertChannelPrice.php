<?php

namespace App\Domain\Catalog\Actions;

use App\Jobs\PushOutboundCommandJob;
use App\Models\ChannelListingVariant;
use App\Models\OutboundCommand;
use App\Models\Workspace;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class UpsertChannelPrice
{
    /**
     * @param  array{
     *   price_amount?: string|float|null,
     *   markup_pct?: string|float|null,
     *   currency_code?: string|null,
     *   sync?: bool,
     *   dry_run?: bool|null
     * }  $input
     */
    public function execute(ChannelListingVariant $listingVariant, array $input): ChannelListingVariant
    {
        $listingVariant->loadMissing(['listing', 'variant']);

        $currency = strtoupper((string) ($input['currency_code']
            ?? $listingVariant->currency_code
            ?? $listingVariant->variant?->base_price_currency
            ?? 'MXN'));

        $price = $input['price_amount'] ?? null;
        $markup = $input['markup_pct'] ?? null;

        if ($price === null && $markup !== null && $listingVariant->variant?->base_price_amount !== null) {
            $base = (string) $listingVariant->variant->base_price_amount;
            $factor = bcadd('1', bcdiv((string) $markup, '100', 8), 8);
            $price = bcmul($base, $factor, 6);
        }

        if ($price === null) {
            throw new InvalidArgumentException('price_amount or markup_pct with base price is required.');
        }

        $listingVariant->price_amount = $price;
        $listingVariant->currency_code = $currency;
        if ($markup !== null) {
            $listingVariant->markup_pct = $markup;
        }
        $listingVariant->save();

        $sync = (bool) ($input['sync'] ?? true);
        if (! $sync) {
            return $listingVariant->fresh(['listing', 'variant']) ?? $listingVariant;
        }

        $listing = $listingVariant->listing;
        if ($listing === null || $listing->external_item_id === null) {
            return $listingVariant->fresh(['listing', 'variant']) ?? $listingVariant;
        }

        $workspace = Workspace::query()->find($listingVariant->workspace_id);
        $dryRun = array_key_exists('dry_run', $input)
            ? (bool) $input['dry_run']
            : (bool) ($workspace?->outbound_dry_run ?? true);

        $command = OutboundCommand::query()->create([
            'workspace_id' => $listingVariant->workspace_id,
            'connection_id' => $listing->connection_id,
            'command_type' => 'price_update',
            'status' => 'pending',
            'dry_run' => $dryRun,
            'payload' => [
                'external_item_id' => $listing->external_item_id,
                'price' => (float) $price,
                'channel_listing_id' => $listing->id,
                'channel_listing_variant_id' => $listingVariant->id,
                'external_variation_id' => $listingVariant->external_variation_id,
            ],
            'idempotency_key' => 'price:'.$listingVariant->id.':'.Str::uuid()->toString(),
            'available_at' => now(),
        ]);

        PushOutboundCommandJob::dispatch(
            (int) $listingVariant->workspace_id,
            (int) $listing->connection_id,
            (int) $command->id,
        );

        $listingVariant->price_synced_at = now();
        $listingVariant->save();

        return $listingVariant->fresh(['listing', 'variant']) ?? $listingVariant;
    }
}
