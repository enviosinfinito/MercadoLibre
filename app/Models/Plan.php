<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'description',
    'price_amount',
    'currency_code',
    'billing_period',
    'is_active',
    'limits',
])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:4',
            'is_active' => 'boolean',
            'limits' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}