<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Support\FullStockOperationType;
use App\Models\FullStockOperation;
use App\Models\Order;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ResolveOrderStockEvidence
{
    public function __construct(
        private readonly ResolveFullCommerceLinks $resolveFullLinks,
    ) {}

    /**
     * @return array{
     *   done: bool,
     *   at: ?Carbon,
     *   reservations: Collection<int, Reservation>,
     *   full_operations: Collection<int, FullStockOperation>,
     *   sale_confirmations: Collection<int, FullStockOperation>
     * }
     */
    public function execute(Order $order): array
    {
        $reservations = Reservation::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $fullOperations = $this->resolveFullLinks->findOperationsForCommerceIds(
            (int) $order->workspace_id,
            $order->connection_id !== null ? (int) $order->connection_id : null,
            $this->resolveFullLinks->commerceIdsForOrder($order),
        );

        $saleConfirmations = $fullOperations
            ->filter(fn (FullStockOperation $op) => FullStockOperationType::isSaleConfirmation((string) $op->operation_type))
            ->values();

        $evidenceReservations = $reservations->filter(
            fn (Reservation $reservation) => in_array($reservation->status, ['active', 'fulfilled'], true),
        );

        $dates = [];
        foreach ($evidenceReservations as $reservation) {
            if ($reservation->reserved_at !== null) {
                $dates[] = $reservation->reserved_at;
            }
        }
        foreach ($saleConfirmations as $operation) {
            if ($operation->occurred_at !== null) {
                $dates[] = $operation->occurred_at;
            }
        }

        $at = null;
        if ($dates !== []) {
            usort($dates, static fn ($a, $b) => $a <=> $b);
            $at = $dates[0];
        }

        return [
            'done' => $evidenceReservations->isNotEmpty() || $saleConfirmations->isNotEmpty(),
            'at' => $at,
            'reservations' => $reservations,
            'full_operations' => $fullOperations,
            'sale_confirmations' => $saleConfirmations,
        ];
    }
}
