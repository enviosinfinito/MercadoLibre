<?php

namespace App\Models;

use App\Domain\Sales\Support\OrderSalesClassification;
use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'connection_id',
    'pack_id',
    'external_order_id',
    'status',
    'post_sale_outcome',
    'buyer_external_id',
    'currency_code',
    'total_amount',
    'ordered_at',
    'paid_at',
    'cancelled_at',
    'raw_snapshot_id',
    'meta',
])]
class Order extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:6',
            'ordered_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function profitSnapshots(): HasMany
    {
        return $this->hasMany(ProfitSnapshot::class);
    }

    public function financialEvents(): HasMany
    {
        return $this->hasMany(FinancialEvent::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OrderMessage::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function returnCases(): HasMany
    {
        return $this->hasMany(ReturnCase::class, 'order_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function marketplacePayments(): HasMany
    {
        return $this->hasMany(MarketplacePayment::class);
    }

    public function cashReconciliationLinks(): HasMany
    {
        return $this->hasMany(CashReconciliationLink::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return OrderSalesClassification::scopeSuccessful($query);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCancelledStatus(Builder $query): Builder
    {
        return OrderSalesClassification::scopeCancelled($query);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePostSaleReversed(Builder $query): Builder
    {
        return OrderSalesClassification::scopePostSaleReversed($query);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeClaimOpen(Builder $query): Builder
    {
        return OrderSalesClassification::scopeClaimOpen($query);
    }

    /**
     * Pack id used by Mercado Libre messaging API (pack_id or order_id fallback).
     */
    public function messagingPackExternalId(): ?string
    {
        if ($this->pack?->external_pack_id) {
            return (string) $this->pack->external_pack_id;
        }

        $metaPack = $this->meta['messaging_pack_id'] ?? $this->meta['pack_id'] ?? null;
        if (is_string($metaPack) && $metaPack !== '') {
            return $metaPack;
        }
        if (is_int($metaPack) || (is_string($metaPack) && is_numeric($metaPack))) {
            return (string) $metaPack;
        }

        return $this->external_order_id !== null && $this->external_order_id !== ''
            ? (string) $this->external_order_id
            : null;
    }
}
