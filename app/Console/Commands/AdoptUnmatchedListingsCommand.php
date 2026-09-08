<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\AdoptListingAsCanonicalProduct;
use App\Models\ChannelListing;
use Illuminate\Console\Command;

class AdoptUnmatchedListingsCommand extends Command
{
    protected $signature = 'catalog:adopt-unmatched-listings
                            {--workspace= : Limit to a workspace ID}
                            {--dry-run : Count listings without adopting}';

    protected $description = 'Adopt unmatched channel listing variants as canonical products/SKUs';

    public function handle(AdoptListingAsCanonicalProduct $adopt): int
    {
        $query = ChannelListing::query()
            ->whereIn('status', ['active', 'paused'])
            ->whereHas('variants', fn ($q) => $q->whereNull('variant_id'))
            ->orderBy('id');

        if ($this->option('workspace')) {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        $dryRun = (bool) $this->option('dry-run');
        $adopted = 0;

        $query->with('variants')->each(function (ChannelListing $listing) use ($adopt, $dryRun, &$adopted) {
            if ($dryRun) {
                $this->line("Would adopt listing {$listing->id} ({$listing->external_item_id})");
                $adopted++;

                return;
            }

            $adopt->execute($listing);
            $adopted++;
        });

        $this->info(($dryRun ? 'Would adopt' : 'Adopted')." {$adopted} listing(s).");

        return self::SUCCESS;
    }
}
