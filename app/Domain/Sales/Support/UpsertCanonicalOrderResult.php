<?php

namespace App\Domain\Sales\Support;

use App\Models\Order;

final class UpsertCanonicalOrderResult
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $wasCreated,
    ) {}
}
