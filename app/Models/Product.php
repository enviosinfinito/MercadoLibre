<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'description',
    'status',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function channelListingVariants(): HasManyThrough
    {
        return $this->hasManyThrough(
            ChannelListingVariant::class,
            Variant::class,
            'product_id',
            'variant_id',
            'id',
            'id',
        );
    }

    public function returnDailyStats(): HasMany
    {
        return $this->hasMany(ReturnProductDailyStat::class);
    }

    public function returnActions(): HasMany
    {
        return $this->hasMany(ReturnAction::class);
    }

    public function returnNarratives(): HasMany
    {
        return $this->hasMany(ReturnProductNarrative::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Products that have at least one non-deleted variant with no cost layers.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeWithoutCost(Builder $query): Builder
    {
        return $query->whereHas(
            'variants',
            fn (Builder $variants) => $variants->whereDoesntHave('costLayers'),
        );
    }

    public static function countWithoutCostForWorkspace(int $workspaceId): int
    {
        return static::query()
            ->where('workspace_id', $workspaceId)
            ->withoutCost()
            ->count();
    }
}
