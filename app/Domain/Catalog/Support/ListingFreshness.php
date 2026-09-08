<?php

namespace App\Domain\Catalog\Support;

use App\Models\ChannelListing;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class ListingFreshness
{
    /**
     * @param  array<string, mixed>  $item
     */
    public static function contentChecksum(array $item): string
    {
        $copy = $item;
        unset($copy['description'], $copy['raw_snapshot_id'], $copy['content_checksum']);

        return hash('sha256', json_encode($copy, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function externalUpdatedAt(array $item): ?CarbonInterface
    {
        $raw = $item['last_updated'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Skip description API when local copy is fresh relative to ML last_updated / checksum.
     */
    public static function shouldFetchDescription(?ChannelListing $listing, array $item, string $checksum): bool
    {
        if ($listing === null) {
            return true;
        }

        if (! filled($listing->description)) {
            return true;
        }

        $remoteUpdated = self::externalUpdatedAt($item);
        if (
            $remoteUpdated !== null
            && $listing->external_updated_at !== null
            && $listing->external_updated_at->equalTo($remoteUpdated)
        ) {
            return false;
        }

        if (
            is_string($listing->content_checksum)
            && $listing->content_checksum !== ''
            && hash_equals($listing->content_checksum, $checksum)
        ) {
            return false;
        }

        return true;
    }
}
