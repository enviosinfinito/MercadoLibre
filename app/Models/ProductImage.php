<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'workspace_id',
    'product_id',
    'file_id',
    'channel_listing_id',
    'sort_order',
    'publish_status',
    'external_picture_id',
    'published_at',
])]
class ProductImage extends Model
{
    use BelongsToWorkspace;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(FileAsset::class, 'file_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ChannelListing::class, 'channel_listing_id');
    }

    public function isPending(): bool
    {
        return $this->publish_status === self::STATUS_PENDING;
    }

    public function url(): ?string
    {
        $file = $this->file;
        if ($file === null || $file->path === '') {
            return null;
        }

        try {
            return Storage::disk($file->disk)->url($file->path);
        } catch (\Throwable) {
            return null;
        }
    }
}
