<?php

namespace App\Domain\Returns\Actions;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Domain\Sales\Support\OrderSalesClassification;
use App\Jobs\Returns\AnalyzeReturnMessagesJob;
use App\Jobs\Returns\UpdateReturnProductStatisticsJob;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Claim;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ReturnCase;
use App\Models\ReturnCaseItem;
use App\Models\Shipment;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

final class ProjectReturnCaseFromClaim
{
    public function execute(Claim $claim, bool $dispatchFollowUps = true): ?ReturnCase
    {
        $claim->loadMissing(['order.lines.variant', 'order.connection']);
        $order = $claim->order;
        if ($order === null) {
            return null;
        }

        $outcome = $order->post_sale_outcome;
        if ($outcome === null || ! in_array($outcome, config('returns.outcomes', OrderSalesClassification::reversedOutcomes()), true)) {
            ReturnCase::query()
                ->where('claim_id', $claim->id)
                ->delete();

            return null;
        }

        $shipment = Shipment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('delivered_at')
            ->first();

        $deliveredAt = $shipment?->delivered_at;
        $openedAt = $claim->opened_at ?? $claim->created_at;
        $daysToReturn = null;
        if ($deliveredAt !== null && $openedAt !== null && $openedAt->greaterThanOrEqualTo($deliveredAt)) {
            $daysToReturn = (int) $deliveredAt->diffInDays($openedAt);
        }

        $returnedAmount = (string) ($order->total_amount ?? '0');
        $dominantProductId = null;
        $dominantMlItemId = null;

        $returnCase = DB::transaction(function () use (
            $claim,
            $order,
            $outcome,
            $deliveredAt,
            $openedAt,
            $daysToReturn,
            $returnedAmount,
            &$dominantProductId,
            &$dominantMlItemId,
        ): ReturnCase {
            $returnCase = ReturnCase::query()->updateOrCreate(
                ['claim_id' => $claim->id],
                [
                    'workspace_id' => $claim->workspace_id,
                    'connection_id' => $claim->connection_id ?? $order->connection_id,
                    'order_id' => $order->id,
                    'external_return_id' => $claim->external_claim_id,
                    'status' => $claim->status,
                    'outcome' => $outcome,
                    'reason' => $claim->reason,
                    'reason_id' => $claim->reason_id,
                    'reason_label' => $claim->reason_detail ?: $claim->reason,
                    'reason_group' => $this->mapMlReasonGroup($claim),
                    'buyer_comment' => $claim->problem,
                    'opened_at' => $openedAt,
                    'closed_at' => $claim->closed_at,
                    'ordered_at' => $order->ordered_at,
                    'delivered_at' => $deliveredAt,
                    'days_to_return' => $daysToReturn,
                    'returned_amount' => $returnedAmount,
                    'currency_code' => $order->currency_code ?? 'MXN',
                    'estimated_loss' => $returnedAmount,
                    'meta' => [
                        'claim_type' => $claim->type,
                        'claim_stage' => $claim->stage,
                        'resolution_reason' => $claim->resolutionReason(),
                    ],
                ],
            );

            ReturnCaseItem::query()->where('return_id', $returnCase->id)->delete();

            foreach ($order->lines as $line) {
                $productId = $line->variant?->product_id;
                $mlItemId = $line->external_item_id;
                $variantLabel = $this->resolveVariantLabel($line);
                $channelListingId = $this->resolveChannelListingId($order, $line);

                if ($dominantProductId === null && $productId !== null) {
                    $dominantProductId = $productId;
                }
                if ($dominantMlItemId === null && filled($mlItemId)) {
                    $dominantMlItemId = $mlItemId;
                }

                ReturnCaseItem::query()->create([
                    'workspace_id' => $order->workspace_id,
                    'return_id' => $returnCase->id,
                    'order_line_id' => $line->id,
                    'product_id' => $productId,
                    'variant_id' => $line->variant_id,
                    'channel_listing_id' => $channelListingId,
                    'ml_item_id' => $mlItemId,
                    'ml_variation_id' => $line->external_variation_id,
                    'sku' => $line->sku ?? $line->variant?->sku,
                    'title' => $line->title,
                    'variant_label' => $variantLabel,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => $line->unit_price_amount,
                    'line_amount' => $line->line_total_amount,
                    'reason_id' => $claim->reason_id,
                    'reason_label' => $claim->reason_detail ?: $claim->reason,
                ]);
            }

            $returnCase->forceFill([
                'dominant_product_id' => $dominantProductId,
                'dominant_ml_item_id' => $dominantMlItemId,
            ])->save();

            return $returnCase->fresh(['items']) ?? $returnCase;
        });

        if ($dispatchFollowUps) {
            AnalyzeReturnMessagesJob::dispatch(
                (int) $returnCase->workspace_id,
                (int) ($returnCase->connection_id ?? 0),
                (int) $returnCase->id,
            );

            UpdateReturnProductStatisticsJob::dispatch(
                (int) $returnCase->workspace_id,
                (int) ($returnCase->connection_id ?? 0),
                $returnCase->opened_at?->toDateString() ?? now()->toDateString(),
                $returnCase->dominant_product_id,
                $returnCase->dominant_ml_item_id,
            );
        }

        return $returnCase;
    }

    public function executeForOrder(Order $order, bool $dispatchFollowUps = true): void
    {
        $outcomes = config('returns.outcomes', OrderSalesClassification::reversedOutcomes());
        if ($order->post_sale_outcome === null || ! in_array($order->post_sale_outcome, $outcomes, true)) {
            ReturnCase::query()->where('order_id', $order->id)->delete();

            return;
        }

        $claims = Claim::query()
            ->where('order_id', $order->id)
            ->where('status', 'closed')
            ->orderByDesc('id')
            ->get();

        if ($claims->isEmpty()) {
            $open = Claim::query()->where('order_id', $order->id)->orderByDesc('id')->first();
            if ($open !== null) {
                $this->execute($open, $dispatchFollowUps);
            }

            return;
        }

        foreach ($claims as $claim) {
            $reason = $claim->resolutionReason();
            if (in_array($reason, [
                'item_returned',
                'payment_refunded',
                'partial_refunded',
            ], true) || $order->post_sale_outcome !== null) {
                $this->execute($claim, $dispatchFollowUps);
                break;
            }
        }
    }

    private function mapMlReasonGroup(Claim $claim): string
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) $claim->reason_id,
            (string) $claim->reason,
            (string) $claim->reason_detail,
            (string) $claim->problem,
        ])));

        $groups = config('returns.reason_keywords', []);
        foreach ($groups as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($haystack, $this->normalize($keyword))) {
                    return (string) $group;
                }
            }
        }

        return 'other';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);

        return preg_replace('/\s+/', ' ', trim($value)) ?? $value;
    }

    private function resolveVariantLabel(OrderLine $line): ?string
    {
        if ($line->variant_id !== null) {
            $variant = $line->variant ?? Variant::query()->find($line->variant_id);
            if ($variant?->name) {
                return $variant->name;
            }
        }

        if (filled($line->external_variation_id) && filled($line->external_item_id)) {
            $clv = ChannelListingVariant::query()
                ->where('external_variation_id', $line->external_variation_id)
                ->whereHas('listing', fn ($q) => $q->where('external_item_id', $line->external_item_id))
                ->first();

            if (is_array($clv?->attribute_combinations) && $clv->attribute_combinations !== []) {
                $parts = [];
                foreach ($clv->attribute_combinations as $attr) {
                    if (! is_array($attr)) {
                        continue;
                    }
                    $name = $attr['name'] ?? null;
                    $value = $attr['value_name'] ?? null;
                    if (filled($value)) {
                        $parts[] = filled($name) ? "{$name}: {$value}" : (string) $value;
                    }
                }
                if ($parts !== []) {
                    return implode(' · ', $parts);
                }
            }
        }

        return $line->sku;
    }

    private function resolveChannelListingId(Order $order, OrderLine $line): ?int
    {
        if (! filled($line->external_item_id)) {
            return null;
        }

        return ChannelListing::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('connection_id', $order->connection_id)
            ->where('external_item_id', $line->external_item_id)
            ->value('id');
    }
}
