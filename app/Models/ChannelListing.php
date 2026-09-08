<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
        'workspace_id',
        'connection_id',
        'product_id',
        'provider',
        'external_item_id',
        'title',
        'status',
        'category_id',
        'category_name',
        'logistic_type',
        'inventory_id',
        'shipping_meta',
        'permalink',
        'description',
        'pictures',
        'attributes_meta',
        'purchase_experience',
        'purchase_experience_synced_at',
        'pe_color',
        'pe_value',
        'raw_snapshot_id',
        'external_updated_at',
        'content_checksum',
])]
class ChannelListing extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'pictures' => 'array',
            'attributes_meta' => 'array',
            'shipping_meta' => 'array',
            'purchase_experience' => 'array',
            'purchase_experience_synced_at' => 'datetime',
            'pe_value' => 'integer',
            'external_updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ChannelListingVariant::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Active marketplace listings that still need a usable cost:
     * unmatched CLVs, or matched variants without cost layers.
     *
     * @param  Builder<ChannelListing>  $query
     * @return Builder<ChannelListing>
     */
    public function scopeWithoutCost(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $builder) {
                $builder
                    ->whereHas('variants', fn (Builder $variants) => $variants->whereNull('variant_id'))
                    ->orWhereHas(
                        'variants',
                        fn (Builder $variants) => $variants
                            ->whereNotNull('variant_id')
                            ->whereDoesntHave('variant.costLayers'),
                    );
            });
    }

    public static function countWithoutCostForWorkspace(int $workspaceId): int
    {
        return static::query()
            ->where('workspace_id', $workspaceId)
            ->withoutCost()
            ->count();
    }

    public function isMissingCost(): bool
    {
        if ((string) $this->status !== 'active') {
            return false;
        }

        $this->loadMissing('variants.variant.costLayers');

        foreach ($this->variants as $listingVariant) {
            if ($listingVariant->variant_id === null) {
                return true;
            }

            $canonical = $listingVariant->variant;
            if ($canonical === null || $canonical->costLayers->isEmpty()) {
                return true;
            }
        }

        return false;
    }
}
