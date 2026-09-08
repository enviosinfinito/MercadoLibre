<?php

namespace App\Domain\Inventory\Actions;

use App\Models\CostLayer;
use App\Models\CostLayerConsumption;
use App\Models\CostSnapshot;
use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ConsumeCostLayersFifo
{
    /**
     * @return array{consumptions: list<CostLayerConsumption>, snapshot: CostSnapshot, total_cogs: string}
     */
    public function execute(OrderLine $orderLine): array
    {
        if ($orderLine->variant_id === null) {
            throw new RuntimeException('Cannot consume cost layers for unmatched order line.');
        }

        return DB::transaction(function () use ($orderLine) {
            $existing = CostSnapshot::query()
                ->where('order_line_id', $orderLine->id)
                ->first();

            if ($existing) {
                $consumptions = CostLayerConsumption::query()
                    ->where('order_line_id', $orderLine->id)
                    ->get()
                    ->all();

                return [
                    'consumptions' => $consumptions,
                    'snapshot' => $existing,
                    'total_cogs' => (string) $existing->total_cogs_reporting_amount,
                ];
            }

            $remaining = (string) $orderLine->quantity;
            $totalCogs = '0';
            $payload = [];
            $consumptions = [];

            $layers = CostLayer::query()
                ->where('workspace_id', $orderLine->workspace_id)
                ->where('variant_id', $orderLine->variant_id)
                ->where('qty_remaining', '>', 0)
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($layers as $layer) {
                if (bccomp($remaining, '0', 6) !== 1) {
                    break;
                }

                $take = bccomp($remaining, (string) $layer->qty_remaining, 6) === 1
                    ? (string) $layer->qty_remaining
                    : $remaining;

                $layer->qty_remaining = bcsub((string) $layer->qty_remaining, $take, 6);
                $layer->save();

                $lineCost = bcmul($take, (string) $layer->unit_cost_reporting_amount, 6);
                $totalCogs = bcadd($totalCogs, $lineCost, 6);

                $consumption = CostLayerConsumption::query()->create([
                    'workspace_id' => $orderLine->workspace_id,
                    'cost_layer_id' => $layer->id,
                    'order_line_id' => $orderLine->id,
                    'quantity' => $take,
                    'unit_cost_reporting_amount' => $layer->unit_cost_reporting_amount,
                    'currency_code' => $layer->reporting_currency,
                    'total_cost_reporting_amount' => $lineCost,
                ]);

                $consumptions[] = $consumption;
                $payload[] = [
                    'cost_layer_id' => $layer->id,
                    'quantity' => $take,
                    'unit_cost_reporting_amount' => (string) $layer->unit_cost_reporting_amount,
                    'total' => $lineCost,
                ];

                $remaining = bcsub($remaining, $take, 6);
            }

            $snapshot = CostSnapshot::query()->create([
                'workspace_id' => $orderLine->workspace_id,
                'order_line_id' => $orderLine->id,
                'payload' => [
                    'layers' => $payload,
                    'unfulfilled_qty' => $remaining,
                ],
                'total_cogs_reporting_amount' => $totalCogs,
                'currency_code' => $orderLine->currency_code,
            ]);

            return [
                'consumptions' => $consumptions,
                'snapshot' => $snapshot,
                'total_cogs' => $totalCogs,
            ];
        });
    }
}
