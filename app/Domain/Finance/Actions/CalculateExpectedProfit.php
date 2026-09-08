<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Inventory\Actions\ConsumeCostLayersFifo;
use App\Domain\Shared\ValueObjects\Money;
use App\Models\FinancialEvent;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use Illuminate\Support\Facades\DB;

final class CalculateExpectedProfit
{
    /** Marketplace fee-like events (absolute of stored amount → fees_amount). */
    private const FEE_LIKE_TYPES = [
        'expected_fee',
        'expected_fee_sale',
        'expected_shipping_cost',
        'expected_advertising',
    ];

    /** Government retention events (absolute → taxes_retention bucket). */
    private const TAX_RETENTION_TYPES = [
        'expected_tax_isr',
        'expected_tax_iva',
        'expected_tax_retention',
    ];

    /** Event types that reduce revenue_amount (absolute of stored amount). */
    private const REVENUE_REDUCE_TYPES = [
        'expected_refund',
        'expected_discount',
    ];

    private const FEE_LABELS = [
        'expected_fee' => 'Comisión (genérica)',
        'expected_fee_sale' => 'Comisión de venta',
        'expected_shipping_cost' => 'Costo de envío',
        'expected_advertising' => 'Publicidad',
    ];

    private const TAX_LABELS = [
        'expected_tax_isr' => 'Retención ISR',
        'expected_tax_iva' => 'Retención IVA',
        'expected_tax_retention' => 'Impuestos / retenciones',
    ];

    public function __construct(
        private readonly ConsumeCostLayersFifo $consumeCostLayersFifo,
    ) {}

    public function execute(Order $order): ProfitSnapshot
    {
        return DB::transaction(function () use ($order) {
            $order->loadMissing('lines');

            $currency = (string) $order->currency_code;
            $revenue = new Money('0', $currency);
            $fees = new Money('0', $currency);
            $taxes = new Money('0', $currency);
            $cogs = new Money('0', $currency);
            $incomplete = false;

            foreach ($order->lines as $line) {
                $revenue = $revenue->add(new Money((string) $line->line_total_amount, $currency));

                if ($line->variant_id) {
                    try {
                        $result = $this->consumeCostLayersFifo->execute($line);
                        $cogs = $cogs->add(new Money($result['total_cogs'], $currency));
                        $unfulfilled = (string) ($result['snapshot']->payload['unfulfilled_qty'] ?? '0');
                        if (bccomp($unfulfilled, '0', 6) === 1) {
                            $incomplete = true;
                        }
                    } catch (\Throwable) {
                        $incomplete = true;
                    }
                } else {
                    $incomplete = true;
                }
            }

            $events = FinancialEvent::query()
                ->where('order_id', $order->id)
                ->where('stage', 'expected')
                ->orderBy('id')
                ->get();

            $feeRows = [];
            $taxRows = [];
            $refundRows = [];

            foreach ($events as $event) {
                $type = (string) $event->event_type;
                $abs = $this->absoluteAmount((string) $event->amount);

                if (in_array($type, self::FEE_LIKE_TYPES, true)) {
                    $fees = $fees->add(new Money($abs, $currency));
                    $feeRows[] = $this->breakdownRow(
                        $type,
                        self::FEE_LABELS[$type] ?? $type,
                        $abs,
                        is_array($event->provenance) ? $event->provenance : null,
                    );
                } elseif (in_array($type, self::TAX_RETENTION_TYPES, true)) {
                    $taxes = $taxes->add(new Money($abs, $currency));
                    $taxRows[] = $this->breakdownRow(
                        $type,
                        self::TAX_LABELS[$type] ?? $type,
                        $abs,
                        is_array($event->provenance) ? $event->provenance : null,
                    );
                } elseif (in_array($type, self::REVENUE_REDUCE_TYPES, true)) {
                    $revenue = $revenue->subtract(new Money($abs, $currency));
                    $label = $type === 'expected_refund'
                        ? (is_array($event->provenance) && is_string($event->provenance['note'] ?? null)
                            ? (string) $event->provenance['note']
                            : 'Salida de pago (reembolso)')
                        : 'Descuento';
                    $refundRows[] = $this->breakdownRow(
                        $type,
                        $label,
                        $abs,
                        is_array($event->provenance) ? $event->provenance : null,
                    );
                }
            }

            $profit = $revenue->subtract($fees)->subtract($taxes)->subtract($cogs);

            $feesTotal = $this->scale6($fees->amount);
            $taxesTotal = $this->scale6($taxes->amount);
            $marketplaceNet = $this->scale6($revenue->subtract($fees)->subtract($taxes)->amount);

            $netReceivedFromApi = null;
            foreach ($events as $event) {
                if (! in_array((string) $event->event_type, self::TAX_RETENTION_TYPES, true)) {
                    continue;
                }
                $prov = is_array($event->provenance) ? $event->provenance : [];
                if (isset($prov['net_received']) && is_numeric($prov['net_received'])) {
                    $netReceivedFromApi = $this->scale6((string) $prov['net_received']);
                    break;
                }
            }

            return ProfitSnapshot::query()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'stage' => 'expected',
                    'calculation_version_id' => null,
                ],
                [
                    'workspace_id' => $order->workspace_id,
                    'revenue_amount' => $this->scale6($revenue->amount),
                    'fees_amount' => $feesTotal,
                    'cogs_amount' => $this->scale6($cogs->amount),
                    'profit_amount' => $this->scale6($profit->amount),
                    'currency_code' => $order->currency_code,
                    'is_incomplete' => $incomplete,
                    'payload' => [
                        'lines' => $order->lines->count(),
                        'taxes_retention_total' => $taxesTotal,
                        // What ML deposits after fees + taxes (before internal COGS).
                        'marketplace_net_amount' => $marketplaceNet,
                        'net_received_amount' => $netReceivedFromApi ?? $marketplaceNet,
                        'post_sale_outcome' => $order->post_sale_outcome,
                        'sale_reversed' => in_array($order->post_sale_outcome, ['returned', 'refunded'], true),
                        'breakdown' => [
                            'fees' => $feeRows,
                            'fees_total' => $feesTotal,
                            'taxes_retention' => $taxRows,
                            'taxes_retention_total' => $taxesTotal,
                            'refunds' => $refundRows,
                            'marketplace_net' => $marketplaceNet,
                        ],
                    ],
                ],
            );
        });
    }

    /**
     * @param  array<string, mixed>|null  $provenance
     * @return array{event_type: string, label: string, amount: string, provenance: array<string, mixed>|null}
     */
    private function breakdownRow(string $type, string $label, string $amount, ?array $provenance): array
    {
        return [
            'event_type' => $type,
            'label' => $label,
            'amount' => $this->scale6($amount),
            'provenance' => $provenance,
        ];
    }

    private function absoluteAmount(string $amount): string
    {
        return bccomp($amount, '0', 8) === -1
            ? bcmul($amount, '-1', 8)
            : $amount;
    }

    private function scale6(string $amount): string
    {
        return bcadd($amount, '0', 6);
    }
}
