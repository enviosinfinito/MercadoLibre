<?php

namespace App\Domain\Ads\Services;

use App\Domain\Ads\Actions\AttributeAdvertisingToOrders;
use App\Models\AdActionProposal;
use App\Models\AdCampaign;
use App\Models\AdSpendDaily;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\FinancialEvent;
use App\Models\OrderLine;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Drill-down payload for a Product Ads item (assistant slide).
 */
final class AdsEntityDetailQuery
{
    public function __construct(
        private readonly AdsMetricsQuery $metricsQuery,
        private readonly AdsProfitabilityService $profitability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forItem(
        int $workspaceId,
        string $mlItemId,
        ?int $connectionId = null,
        ?int $lookbackDays = null,
    ): array {
        $days = $lookbackDays ?? (int) config('ads.evaluate_lookback_days', 14);
        $card = $this->profitability->scorecard($workspaceId, $days);

        $row = collect($card['scorecard'])->first(function (array $r) use ($mlItemId, $connectionId) {
            if (($r['ml_item_id'] ?? '') !== $mlItemId) {
                return false;
            }
            if ($connectionId && (int) ($r['connection_id'] ?? 0) !== $connectionId) {
                return false;
            }

            return true;
        });

        $connectionIds = $connectionId ? [$connectionId] : (
            is_array($row) && ! empty($row['connection_id']) ? [(int) $row['connection_id']] : []
        );

        $metrics = $this->metricsQuery->execute($workspaceId, [
            'period' => 'custom',
            'from' => $card['period']['start'],
            'to' => $card['period']['end'],
            'connection_ids' => $connectionIds,
            'item_ids' => [$mlItemId],
            'group_by' => 'day',
        ]);

        $byCampaign = $this->metricsQuery->execute($workspaceId, [
            'period' => 'custom',
            'from' => $card['period']['start'],
            'to' => $card['period']['end'],
            'connection_ids' => $connectionIds,
            'item_ids' => [$mlItemId],
            'group_by' => 'campaign',
        ]);

        $dailyRows = AdSpendDaily::query()
            ->where('workspace_id', $workspaceId)
            ->where('ml_item_id', $mlItemId)
            ->when($connectionIds !== [], fn ($q) => $q->whereIn('connection_id', $connectionIds))
            ->whereDate('date', '>=', $card['period']['start'])
            ->whereDate('date', '<=', $card['period']['end'])
            ->orderBy('date')
            ->get([
                'date', 'cost', 'clicks', 'prints', 'cpc', 'ctr',
                'direct_amount', 'indirect_amount', 'total_amount',
                'direct_units_quantity', 'indirect_units_quantity',
                'organic_units_quantity', 'organic_units_amount', 'organic_items_quantity',
                'acos', 'roas', 'external_campaign_id', 'ad_campaign_id',
            ]);

        $daily = $dailyRows
            ->groupBy(fn (AdSpendDaily $d) => optional($d->date)?->toDateString() ?: 'unknown')
            ->map(function ($group, string $date) {
                $cost = (float) $group->sum('cost');
                $clicks = (int) $group->sum('clicks');
                $prints = (int) $group->sum('prints');
                $direct = (float) $group->sum('direct_amount');
                $indirect = (float) $group->sum('indirect_amount');
                $revenue = (float) $group->sum(fn (AdSpendDaily $d) => (float) ($d->total_amount ?: ((float) $d->direct_amount + (float) $d->indirect_amount)));
                $units = (float) $group->sum(fn (AdSpendDaily $d) => (float) $d->direct_units_quantity + (float) $d->indirect_units_quantity);
                $organicAmount = (float) $group->sum('organic_units_amount');
                $organicUnits = (float) $group->sum('organic_units_quantity');

                return [
                    'date' => $date,
                    'cost' => round($cost, 2),
                    'clicks' => $clicks,
                    'prints' => $prints,
                    'cpc' => $clicks > 0 ? round($cost / $clicks, 4) : null,
                    'ctr' => $prints > 0 ? round($clicks / $prints, 6) : null,
                    'revenue' => round($revenue, 2),
                    'direct_amount' => round($direct, 2),
                    'indirect_amount' => round($indirect, 2),
                    'organic_amount' => round($organicAmount, 2),
                    'organic_units' => round($organicUnits, 2),
                    'units' => round($units, 2),
                    'roas' => $cost > 0 ? round($revenue / $cost, 4) : null,
                    'acos' => $revenue > 0 ? round($cost / $revenue, 6) : null,
                    'waste' => $cost > 0 && $revenue <= 0,
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        $listing = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->where('external_item_id', $mlItemId)
            ->when($connectionIds !== [], fn ($q) => $q->whereIn('connection_id', $connectionIds))
            ->first(['id', 'title', 'permalink', 'product_id', 'status', 'external_item_id', 'connection_id']);

        $connectionModel = null;
        $resolvedConnectionId = $connectionIds[0]
            ?? (is_array($row) ? ($row['connection_id'] ?? null) : null)
            ?? $listing?->connection_id;
        if ($resolvedConnectionId) {
            $connectionModel = Connection::query()
                ->where('workspace_id', $workspaceId)
                ->where('id', (int) $resolvedConnectionId)
                ->first(['id', 'display_name', 'external_user_id', 'color', 'provider']);
        }

        $connectionPayload = $connectionModel ? [
            'id' => $connectionModel->id,
            'display_name' => $connectionModel->display_name ?: $connectionModel->external_user_id,
            'external_user_id' => $connectionModel->external_user_id,
            'color' => $connectionModel->color,
            'provider' => $connectionModel->provider,
        ] : null;

        $campaignIds = $dailyRows->pluck('external_campaign_id')->filter()->unique()->values()->all();
        $campaignInternalIds = $dailyRows->pluck('ad_campaign_id')->filter()->unique()->values()->all();
        $campaignQuery = AdCampaign::query()->where('workspace_id', $workspaceId);
        if ($campaignIds !== [] || $campaignInternalIds !== [] || (is_array($row) && ! empty($row['ad_campaign_id']))) {
            $campaignQuery->where(function ($q) use ($campaignIds, $campaignInternalIds, $row): void {
                if ($campaignIds !== []) {
                    $q->whereIn('external_campaign_id', $campaignIds);
                }
                if ($campaignInternalIds !== []) {
                    if ($campaignIds !== []) {
                        $q->orWhereIn('id', $campaignInternalIds);
                    } else {
                        $q->whereIn('id', $campaignInternalIds);
                    }
                }
                if (is_array($row) && ! empty($row['ad_campaign_id'])) {
                    $q->orWhere('id', (int) $row['ad_campaign_id']);
                }
            });
        } else {
            $campaignQuery->whereRaw('1 = 0');
        }

        $campaigns = $campaignQuery
            ->limit(20)
            ->get(['id', 'name', 'external_campaign_id', 'status', 'strategy', 'meta'])
            ->map(fn (AdCampaign $c) => [
                'id' => $c->id,
                'name' => $c->name ?: $c->external_campaign_id,
                'external_campaign_id' => $c->external_campaign_id,
                'status' => $c->status,
                'strategy' => $c->strategy,
                'roas_target' => $c->meta['roas_target'] ?? null,
                'budget' => $c->meta['budget'] ?? $c->meta['daily_budget'] ?? null,
            ])
            ->values()
            ->all();

        $campaignsById = collect($campaigns)->keyBy('id');
        $totalCampaignCost = collect($byCampaign['breakdown'] ?? [])->sum(fn ($r) => (float) ($r['cost'] ?? 0));
        $byCampaignRows = collect($byCampaign['breakdown'] ?? [])
            ->map(function (array $r) use ($campaignsById, $totalCampaignCost) {
                $campaignId = isset($r['meta']['campaign_id']) ? (int) $r['meta']['campaign_id'] : null;
                $campaign = $campaignId ? $campaignsById->get($campaignId) : null;
                $cost = (float) ($r['cost'] ?? 0);
                $clicks = (int) ($r['clicks'] ?? 0);

                return array_merge($r, [
                    'status' => $campaign['status'] ?? null,
                    'strategy' => $campaign['strategy'] ?? null,
                    'roas_target' => $campaign['roas_target'] ?? null,
                    'budget' => $campaign['budget'] ?? null,
                    'cpc' => $clicks > 0 ? round($cost / $clicks, 4) : null,
                    'share_of_spend' => $totalCampaignCost > 0
                        ? round($cost / $totalCampaignCost, 4)
                        : null,
                ]);
            })
            ->values()
            ->all();

        $series = collect($metrics['series'] ?? [])
            ->map(function (array $s) {
                $cost = (float) ($s['cost'] ?? 0);
                $revenue = (float) ($s['attributed_revenue'] ?? 0);
                $clicks = (int) ($s['clicks'] ?? 0);

                return array_merge($s, [
                    'roas' => $s['roas'] ?? ($cost > 0 ? round($revenue / $cost, 4) : null),
                    'acos' => $s['acos'] ?? ($revenue > 0 ? round($cost / $revenue, 6) : null),
                    'cpc' => $s['cpc'] ?? ($clicks > 0 ? round($cost / $clicks, 4) : null),
                ]);
            })
            ->values()
            ->all();

        $kpis = $metrics['kpis'];
        $targetRoas = is_array($row)
            ? (float) ($row['target_roas'] ?? $card['catalog_target_roas'])
            : (float) $card['catalog_target_roas'];
        $targetUnreliable = is_array($row) ? (bool) ($row['target_unreliable'] ?? false) : false;
        $marginConfidence = is_array($row) ? (string) ($row['margin_confidence'] ?? 'low') : 'low';
        $targetSource = is_array($row) ? (string) ($row['target_roas_source'] ?? 'margen_default') : 'margen_default';
        $nearRatio = (float) ($card['near_target_ratio'] ?? AdsProfitabilityService::NEAR_TARGET_RATIO);

        $daysWithSpend = collect($series)->filter(fn ($s) => (float) ($s['cost'] ?? 0) > 0);
        $bestRoasDay = $daysWithSpend
            ->filter(fn ($s) => $s['roas'] !== null)
            ->sortByDesc('roas')
            ->first();
        $worstRoasDay = $daysWithSpend
            ->filter(fn ($s) => $s['roas'] !== null)
            ->sortBy('roas')
            ->first();
        $peakSpendDay = $daysWithSpend->sortByDesc('cost')->first();
        $zeroRevenueDays = $daysWithSpend
            ->filter(fn ($s) => (float) ($s['attributed_revenue'] ?? 0) <= 0)
            ->count();
        $daysAboveTarget = $daysWithSpend
            ->filter(fn ($s) => $s['roas'] !== null && (float) $s['roas'] >= $targetRoas)
            ->count();
        $daysBelowTarget = $daysWithSpend
            ->filter(fn ($s) => $s['roas'] !== null && (float) $s['roas'] < $targetRoas)
            ->count();

        // Patterns use a longer window (default 90d) while the chart/KPIs stay on the immediate window.
        $patternDays = max(
            $days,
            min(90, (int) config('ads.pattern_lookback_days', 90)),
        );
        $patternSeries = $series;
        if ($patternDays > $days) {
            $patternMetrics = $this->metricsQuery->execute($workspaceId, [
                'period' => 'custom',
                'from' => now()->startOfDay()->subDays($patternDays - 1)->toDateString(),
                'to' => $card['period']['end'],
                'connection_ids' => $connectionIds,
                'item_ids' => [$mlItemId],
                'group_by' => 'day',
            ]);
            $patternSeries = collect($patternMetrics['series'] ?? [])
                ->map(function (array $s) {
                    $cost = (float) ($s['cost'] ?? 0);
                    $revenue = (float) ($s['attributed_revenue'] ?? 0);
                    $clicks = (int) ($s['clicks'] ?? 0);

                    return array_merge($s, [
                        'roas' => $s['roas'] ?? ($cost > 0 ? round($revenue / $cost, 4) : null),
                        'acos' => $s['acos'] ?? ($revenue > 0 ? round($cost / $revenue, 6) : null),
                        'cpc' => $s['cpc'] ?? ($clicks > 0 ? round($cost / $clicks, 4) : null),
                    ]);
                })
                ->values()
                ->all();
        }
        $patternDaysWithSpend = collect($patternSeries)->filter(fn ($s) => (float) ($s['cost'] ?? 0) > 0);
        $patternWorstDay = $patternDaysWithSpend
            ->filter(fn ($s) => $s['roas'] !== null)
            ->sortBy('roas')
            ->first();
        $worstRoasPattern = $this->buildWorstRoasPattern(
            $patternDaysWithSpend->values()->all(),
            is_array($patternWorstDay) ? $patternWorstDay : null,
            $targetRoas,
            $nearRatio,
        );
        if (is_array($worstRoasPattern)) {
            $worstRoasPattern['immediate_days'] = $days;
            $worstRoasPattern['pattern_days'] = $patternDays;
            $worstRoasPattern['period'] = [
                'start' => now()->startOfDay()->subDays($patternDays - 1)->toDateString(),
                'end' => $card['period']['end'],
                'days' => $patternDays,
            ];
        }

        $proposals = AdActionProposal::query()
            ->where('workspace_id', $workspaceId)
            ->where('ml_item_id', $mlItemId)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'title', 'reason', 'action_type', 'priority', 'status', 'estimated_impact_amount'])
            ->map(fn (AdActionProposal $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'reason' => $p->reason,
                'action_type' => $p->action_type,
                'priority' => $p->priority,
                'status' => $p->status,
                'estimated_impact_amount' => $p->estimated_impact_amount !== null
                    ? (float) $p->estimated_impact_amount
                    : null,
            ])
            ->values()
            ->all();

        $actualRoas = isset($kpis['roas']) && $kpis['roas'] !== null ? (float) $kpis['roas'] : null;
        $cost = (float) ($kpis['cost'] ?? 0);
        $revenue = (float) ($kpis['attributed_revenue'] ?? 0);
        $actualAcos = isset($kpis['acos']) && $kpis['acos'] !== null
            ? (float) $kpis['acos']
            : ($revenue > 0 ? $cost / $revenue : null);
        $targetAcos = $targetRoas > 0 ? 1 / $targetRoas : null;
        $marginRate = is_array($row)
            ? (float) ($row['margin_rate'] ?? $card['avg_margin_rate'])
            : (float) $card['avg_margin_rate'];
        $share = (float) $card['ads_budget_share_of_margin'];

        $pctOfTarget = ($actualRoas !== null && $targetRoas > 0)
            ? round($actualRoas / $targetRoas, 4)
            : null;
        $zone = 'unknown';
        if ($actualRoas !== null && $targetRoas > 0) {
            if ($actualRoas >= $targetRoas) {
                $zone = 'above';
            } elseif ($actualRoas >= $targetRoas * $nearRatio) {
                $zone = 'near';
            } else {
                $zone = 'below';
            }
        }

        $extraRevenueNeeded = ($targetRoas > 0 && $cost > 0)
            ? max(0, round(($targetRoas * $cost) - $revenue, 2))
            : null;
        $maxSpendAtTarget = ($targetRoas > 0 && $revenue > 0)
            ? round($revenue / $targetRoas, 2)
            : null;
        $spendCutNeeded = ($maxSpendAtTarget !== null && $cost > $maxSpendAtTarget)
            ? round($cost - $maxSpendAtTarget, 2)
            : 0.0;

        $statusLabel = is_array($row)
            ? (string) ($row['status_label'] ?? $this->profitability->statusLabel((string) ($row['status'] ?? 'green')))
            : 'Bien';
        $statusReason = is_array($row)
            ? (string) ($row['status_reason'] ?? '')
            : '';

        $verdict = match ($zone) {
            'above' => 'Estás en o por encima de la meta de ventas por cada $1 de ads.',
            'near' => 'Vas cerca de la meta, pero todavía no cubre lo que tu ganancia necesita.',
            'below' => 'Las ventas por cada $1 de ads están por debajo de lo que tu ganancia permite.',
            default => 'Aún no hay suficiente gasto o ventas atribuidas para leer el rendimiento con confianza.',
        };
        if ($targetUnreliable) {
            $verdict .= ' La meta es provisional porque faltan datos de ganancia.';
        }

        $roasInsight = [
            'actual' => $actualRoas !== null ? round($actualRoas, 2) : null,
            'target' => round($targetRoas, 2),
            'target_raw' => is_array($row) ? ($row['target_roas_raw'] ?? null) : null,
            'target_unreliable' => $targetUnreliable,
            'target_roas_source' => $targetSource,
            'margin_confidence' => $marginConfidence,
            'status_label' => $statusLabel,
            'status_reason' => $statusReason,
            'gap' => $actualRoas !== null ? round($actualRoas - $targetRoas, 2) : null,
            'pct_of_target' => $pctOfTarget,
            'zone' => $zone,
            'near_target_ratio' => $nearRatio,
            'verdict' => $verdict,
            'plain' => $actualRoas !== null
                ? 'Por cada $1 invertido en ads, Mercado Ads atribuyó $'.number_format($actualRoas, 2).' en ventas.'
                : 'Todavía no hay ventas por $1 de ads calculables (falta gasto o revenue atribuido).',
            'formula' => [
                'cost' => round($cost, 2),
                'revenue' => round($revenue, 2),
                'expression' => 'Ventas por $1 de ads = revenue ads ÷ inversión',
            ],
            'acos' => [
                'actual' => $actualAcos !== null ? round($actualAcos, 4) : null,
                'target' => $targetAcos !== null ? round($targetAcos, 4) : null,
                'plain' => $actualAcos !== null
                    ? round($actualAcos * 100, 1).'% del ingreso atribuido se fue en publicidad.'
                    : null,
            ],
            'to_hit_target' => [
                'extra_revenue_needed' => $extraRevenueNeeded,
                'max_spend_at_current_revenue' => $maxSpendAtTarget,
                'spend_cut_needed' => $spendCutNeeded,
            ],
            'days' => [
                'with_spend' => $daysWithSpend->count(),
                'above_target' => $daysAboveTarget,
                'below_target' => $daysBelowTarget,
                'zero_revenue' => $zeroRevenueDays,
                'on_target_pct' => $daysWithSpend->count() > 0
                    ? round($daysAboveTarget / $daysWithSpend->count(), 4)
                    : null,
            ],
            'why_target' => $targetUnreliable
                ? sprintf(
                    'Meta provisional %sx (margen estimado %s%%). Faltan costos/P&L confiables; no usamos el techo 35x para asustarte.',
                    number_format($targetRoas, 2),
                    number_format($marginRate * 100, 0),
                )
                : sprintf(
                    'Meta %sx calculada con tu ganancia estimada %s%% y un tope de ads del %s%% de ese margen.',
                    number_format($targetRoas, 2),
                    number_format($marginRate * 100, 0),
                    number_format($share * 100, 0),
                ),
            'margin_rate' => round($marginRate, 4),
            'ads_budget_share_of_margin' => $share,
        ];

        $cost = (float) ($kpis['cost'] ?? 0);
        $revenue = (float) ($kpis['attributed_revenue'] ?? 0);
        $estimatedMarginDollars = round($revenue * $marginRate, 2);
        $contributionRoas = $cost > 0 ? round($estimatedMarginDollars / $cost, 4) : null;

        $residualQuery = FinancialEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_UNALLOCATED)
            ->where('stage', 'expected')
            ->whereNull('order_id')
            ->where('provenance->ml_item_id', $mlItemId)
            ->whereBetween('occurred_at', [
                Carbon::parse($card['period']['start'])->startOfDay(),
                Carbon::parse($card['period']['end'])->endOfDay(),
            ]);
        if ($connectionIds !== []) {
            $residualQuery->whereIn('connection_id', $connectionIds);
        }
        $adsResidual = round((float) $residualQuery->sum(DB::raw('ABS(amount)')), 2);
        $pnlAds = (float) ($kpis['pnl_ads'] ?? 0);
        $pnlAdsDelta = (float) ($kpis['pnl_ads_delta'] ?? ($cost - $pnlAds));

        $campaignListings = $this->campaignListingsDistribution(
            $workspaceId,
            $mlItemId,
            $connectionIds,
            (string) $card['period']['start'],
            (string) $card['period']['end'],
            $campaigns,
            $dailyRows,
        );

        return [
            'ml_item_id' => $mlItemId,
            'connection' => $connectionPayload,
            'listing' => $listing ? [
                'id' => $listing->id,
                'title' => $listing->title,
                'permalink' => $listing->permalink,
                'product_id' => $listing->product_id,
                'status' => $listing->status,
            ] : [
                'id' => null,
                'title' => is_array($row) ? ($row['title'] ?? null) : null,
                'permalink' => is_array($row) ? ($row['permalink'] ?? null) : null,
                'product_id' => is_array($row) ? ($row['product_id'] ?? null) : null,
                'status' => null,
            ],
            'score' => $row ?: null,
            'period' => $card['period'],
            'kpis' => array_merge($kpis, [
                'contribution_roas' => $contributionRoas,
                'estimated_margin_amount' => $estimatedMarginDollars,
                'ads_residual' => $adsResidual,
            ]),
            'target_roas' => $targetRoas,
            'gap_to_target' => $roasInsight['gap'],
            'roas_insight' => $roasInsight,
            'reconciliation' => [
                'ml_cost' => round($cost, 2),
                'pnl_ads' => round($pnlAds, 2),
                'pnl_ads_delta' => round($pnlAdsDelta, 2),
                'ads_residual' => $adsResidual,
                'alert' => abs($pnlAdsDelta) > max(50.0, $cost * 0.15) || $adsResidual > max(50.0, $cost * 0.2),
                'note' => 'Delta = gasto ML − ads ya cargados en P&L de órdenes. Residual incluye waste (ML atribuyó $0) y shortfall de sync.',
            ],
            'contribution' => [
                'margin_rate' => round($marginRate, 4),
                'estimated_margin_amount' => $estimatedMarginDollars,
                'contribution_roas' => $contributionRoas,
                'plain' => $contributionRoas !== null
                    ? 'Por cada $1 de ads, el margen estimado recuperado es $'.number_format($contributionRoas, 2).' (ventas ads × margen).'
                    : 'Sin gasto suficiente para calcular el retorno de margen.',
            ],
            'trend' => [
                'days_with_spend' => $daysWithSpend->count(),
                'zero_revenue_days' => $zeroRevenueDays,
                'days_above_target' => $daysAboveTarget,
                'days_below_target' => $daysBelowTarget,
                'best_roas_day' => $bestRoasDay ? [
                    'date' => $bestRoasDay['bucket'] ?? $bestRoasDay['label'] ?? null,
                    'roas' => $bestRoasDay['roas'] ?? null,
                    'cost' => $bestRoasDay['cost'] ?? null,
                    'revenue' => $bestRoasDay['attributed_revenue'] ?? null,
                    'clicks' => $bestRoasDay['clicks'] ?? null,
                    'units' => $bestRoasDay['units'] ?? null,
                    'cpc' => $bestRoasDay['cpc'] ?? null,
                ] : null,
                'worst_roas_day' => $worstRoasDay ? [
                    'date' => $worstRoasDay['bucket'] ?? $worstRoasDay['label'] ?? null,
                    'roas' => $worstRoasDay['roas'] ?? null,
                    'cost' => $worstRoasDay['cost'] ?? null,
                    'revenue' => $worstRoasDay['attributed_revenue'] ?? null,
                    'clicks' => $worstRoasDay['clicks'] ?? null,
                    'units' => $worstRoasDay['units'] ?? null,
                    'cpc' => $worstRoasDay['cpc'] ?? null,
                ] : null,
                'peak_spend_day' => $peakSpendDay ? [
                    'date' => $peakSpendDay['bucket'] ?? $peakSpendDay['label'] ?? null,
                    'cost' => $peakSpendDay['cost'] ?? null,
                    'revenue' => $peakSpendDay['attributed_revenue'] ?? null,
                    'roas' => $peakSpendDay['roas'] ?? null,
                ] : null,
                'worst_roas_pattern' => $worstRoasPattern,
            ],
            'series' => $series,
            'by_campaign' => $byCampaignRows,
            'daily' => $daily,
            'campaigns' => $campaigns,
            'campaign_listings' => $campaignListings,
            'proposals' => $proposals,
            'links' => [
                'dashboard' => route('ads.dashboard', array_filter([
                    'period' => 'custom',
                    'from' => $card['period']['start'],
                    'to' => $card['period']['end'],
                    'item_ids' => [$mlItemId],
                    'connection_ids' => $connectionIds ?: null,
                    'group_by' => 'day',
                ])),
                'product_ads' => (is_array($row) && ! empty($row['product_id']))
                    ? route('products.ads', ['product' => $row['product_id']])
                    : null,
                'attributed_sales' => route('ads.assistant.items.sales', array_filter([
                    'mlItemId' => $mlItemId,
                    'days' => $days,
                    'connection_id' => $connectionIds[0] ?? null,
                ])),
            ],
            'data_sources' => [
                'ml' => [
                    'cost', 'clicks', 'prints', 'cpc', 'ctr', 'attributed_revenue',
                    'roas', 'acos', 'direct_units', 'indirect_units', 'campaigns',
                ],
                'local' => [
                    'local_gmv', 'pnl_ads', 'margin_rate', 'stock_qty',
                ],
                'ours' => [
                    'target_roas', 'contribution_roas', 'zone', 'waste', 'ads_residual', 'proposals',
                ],
                'note' => 'ML mide atribución ítem+día (sin order_id). Nosotros cruzamos con tu margen, P&L y órdenes locales.',
            ],
        ];
    }

    /**
     * Local orders for the item in the ads lookback window (proxy for credited sales).
     *
     * @return array<string, mixed>
     */
    public function attributedSales(
        int $workspaceId,
        string $mlItemId,
        ?int $connectionId = null,
        ?int $lookbackDays = null,
    ): array {
        $days = $lookbackDays ?? (int) config('ads.evaluate_lookback_days', 14);
        $end = now()->endOfDay();
        $start = now()->startOfDay()->subDays(max(1, $days) - 1);

        $lines = OrderLine::query()
            ->where('workspace_id', $workspaceId)
            ->where('external_item_id', $mlItemId)
            ->whereHas('order', function ($q) use ($start, $end, $connectionId): void {
                $q->whereBetween('ordered_at', [$start, $end]);
                if ($connectionId) {
                    $q->where('connection_id', $connectionId);
                }
            })
            ->with([
                'order:id,workspace_id,connection_id,external_order_id,status,ordered_at,total_amount,currency_code',
                'order.connection:id,display_name,external_user_id,color,provider',
            ])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $orderIds = $lines->pluck('order_id')->unique()->filter()->values()->all();
        $adsByLine = [];
        if ($orderIds !== []) {
            $events = FinancialEvent::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('order_id', $orderIds)
                ->where('event_type', 'expected_advertising')
                ->where('stage', 'expected')
                ->get(['order_line_id', 'order_id', 'amount']);

            foreach ($events as $event) {
                $key = $event->order_line_id
                    ? 'line:'.(int) $event->order_line_id
                    : 'order:'.(int) $event->order_id;
                $adsByLine[$key] = ($adsByLine[$key] ?? 0) + abs((float) $event->amount);
            }
        }

        $sales = [];
        $totalRevenue = 0.0;
        $totalUnits = 0.0;
        $totalAds = 0.0;

        foreach ($lines as $line) {
            $order = $line->order;
            if ($order === null) {
                continue;
            }
            $lineAds = (float) ($adsByLine['line:'.$line->id] ?? 0);
            if ($lineAds <= 0) {
                $lineAds = (float) ($adsByLine['order:'.$order->id] ?? 0);
            }
            $revenue = (float) $line->line_total_amount;
            $units = (float) $line->quantity;
            $totalRevenue += $revenue;
            $totalUnits += $units;
            $totalAds += $lineAds;

            $sales[] = [
                'order_id' => (int) $order->id,
                'order_line_id' => (int) $line->id,
                'external_order_id' => $order->external_order_id,
                'ordered_at' => optional($order->ordered_at)?->toIso8601String(),
                'ordered_date' => optional($order->ordered_at)?->toDateString(),
                'status' => $order->status,
                'quantity' => $units,
                'line_revenue' => round($revenue, 2),
                'ads_cost_allocated' => round($lineAds, 2),
                'has_ads_allocation' => $lineAds > 0,
                'currency' => $order->currency_code ?: 'MXN',
                'order_url' => route('orders.show', $order->id),
                'connection' => $order->connection ? [
                    'id' => $order->connection->id,
                    'display_name' => $order->connection->display_name ?: $order->connection->external_user_id,
                    'external_user_id' => $order->connection->external_user_id,
                    'color' => $order->connection->color,
                    'provider' => $order->connection->provider,
                ] : null,
            ];
        }

        $mlAttributed = AdSpendDaily::query()
            ->where('workspace_id', $workspaceId)
            ->where('ml_item_id', $mlItemId)
            ->when($connectionId, fn ($q) => $q->where('connection_id', $connectionId))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('
                COALESCE(SUM(total_amount), 0) as revenue,
                COALESCE(SUM(direct_amount), 0) as direct_amount,
                COALESCE(SUM(indirect_amount), 0) as indirect_amount,
                COALESCE(SUM(direct_units_quantity + indirect_units_quantity), 0) as units,
                COALESCE(SUM(organic_units_quantity), 0) as organic_units,
                COALESCE(SUM(organic_units_amount), 0) as organic_amount,
                COALESCE(SUM(organic_items_quantity), 0) as organic_items,
                COALESCE(SUM(cost), 0) as cost
            ')
            ->first();

        $mlRevenue = round((float) ($mlAttributed->revenue ?? 0), 2);
        $mlDirect = round((float) ($mlAttributed->direct_amount ?? 0), 2);
        $mlIndirect = round((float) ($mlAttributed->indirect_amount ?? 0), 2);
        $mlUnits = round((float) ($mlAttributed->units ?? 0), 2);
        $mlOrganicUnits = round((float) ($mlAttributed->organic_units ?? 0), 2);
        $mlOrganicAmount = round((float) ($mlAttributed->organic_amount ?? 0), 2);
        $mlOrganicItems = round((float) ($mlAttributed->organic_items ?? 0), 2);
        $mlCost = round((float) ($mlAttributed->cost ?? 0), 2);

        $residualQuery = FinancialEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_UNALLOCATED)
            ->where('stage', 'expected')
            ->whereNull('order_id')
            ->where('provenance->ml_item_id', $mlItemId)
            ->whereBetween('occurred_at', [$start, $end]);
        if ($connectionId) {
            $residualQuery->where('connection_id', $connectionId);
        }
        $adsResidual = round((float) $residualQuery->sum(DB::raw('ABS(amount)')), 2);

        $revenueCoverage = $mlRevenue > 0 ? round($totalRevenue / $mlRevenue, 4) : null;
        $unitsCoverage = $mlUnits > 0 ? round($totalUnits / $mlUnits, 4) : null;
        $coverage = $revenueCoverage ?? $unitsCoverage;
        $kind = 'ok';
        $coverageNote = 'Tus órdenes cubren lo que ML atribuye a ads.';
        if ($mlCost > 0 && $mlRevenue <= 0) {
            $kind = 'waste';
            $coverageNote = 'Hubo gasto ads y ML no atribuyó ventas. Ese gasto es waste/overhead: no se carga al P&L de órdenes orgánicas.';
        } elseif ($coverage !== null && $coverage < 0.8 && $mlCost > 0) {
            $kind = 'short';
            $coverageNote = 'Nos faltan órdenes sincronizadas vs lo que ML atribuye. Sincronizá el periodo.';
        } elseif ($coverage !== null && $coverage > 1.05) {
            $kind = 'organic_heavy';
            $coverageNote = 'Tenés más ventas locales que las atribuidas a ads (incluye orgánicas). El gasto ads se diluye; las orgánicas no se inventan como conversiones.';
        }
        $needsSync = $kind === 'short';

        $mlAcos = $mlRevenue > 0 ? round($mlCost / $mlRevenue, 4) : null;
        $mlTacos = $totalRevenue > 0 ? round($mlCost / $totalRevenue, 4) : null;

        $syncStatus = null;
        if ($connectionId) {
            $run = SyncRun::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connectionId)
                ->where('resource_type', 'orders')
                ->where(function ($q) {
                    $q->where('mode', 'ads_coverage')
                        ->orWhere('stats->purpose', 'ads_coverage');
                })
                ->orderByDesc('id')
                ->first(['id', 'status', 'started_at', 'finished_at', 'stats', 'error_redacted']);
            if ($run) {
                $stats = is_array($run->stats) ? $run->stats : [];
                $syncStatus = [
                    'sync_run_id' => (int) $run->id,
                    'status' => $run->status,
                    'started_at' => optional($run->started_at)?->toIso8601String(),
                    'finished_at' => optional($run->finished_at)?->toIso8601String(),
                    'error' => $run->error_redacted,
                    'items' => (int) (($stats['items'] ?? 0)),
                    'pages' => (int) (($stats['pages'] ?? 0)),
                    'truncated' => (bool) ($stats['truncated'] ?? false),
                    'truncated_reason' => $stats['truncated_reason'] ?? null,
                ];
            }
        }

        return [
            'ml_item_id' => $mlItemId,
            'connection_id' => $connectionId,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
            ],
            'ml_attribution' => [
                'source' => 'ml',
                'revenue' => $mlRevenue,
                'direct_amount' => $mlDirect,
                'indirect_amount' => $mlIndirect,
                'units' => $mlUnits,
                'organic_units' => $mlOrganicUnits,
                'organic_amount' => $mlOrganicAmount,
                'organic_items' => $mlOrganicItems,
                'cost' => $mlCost,
                'acos' => $mlAcos,
                'tacos' => $mlTacos,
                'roas' => $mlCost > 0 ? round($mlRevenue / $mlCost, 4) : null,
                'waste' => $mlCost > 0 && $mlRevenue <= 0,
                'note' => 'Totales Product Ads: ROAS/ACoS usan solo revenue atribuido; TACoS usa GMV local; orgánicas las reporta ML aparte.',
            ],
            'local_sales' => [
                'source' => 'local',
                'orders_count' => count($sales),
                'units' => round($totalUnits, 2),
                'revenue' => round($totalRevenue, 2),
                'ads_allocated' => round($totalAds, 2),
                'note' => 'Órdenes locales del ítem. Ads en P&L solo si ML atribuyó revenue ese ítem+día.',
            ],
            'allocation' => [
                'source' => 'ours',
                'ads_allocated' => round($totalAds, 2),
                'ads_residual' => $adsResidual,
                'ml_cost' => $mlCost,
                'ml_acos' => $mlAcos,
                'ml_tacos' => $mlTacos,
                'note' => 'Si ML atribuye $0, el gasto queda residual (waste). Si atribuye >0: rate = gasto ÷ max(ventas ML, GMV local).',
            ],
            'coverage' => [
                'revenue_ratio' => $revenueCoverage,
                'units_ratio' => $unitsCoverage,
                'ratio' => $coverage,
                'kind' => $kind,
                'note' => $coverageNote,
                'needs_sync' => $needsSync,
                'threshold' => 0.8,
            ],
            'sync' => $syncStatus,
            'sales' => $sales,
        ];
    }

    /**
     * Heuristics for why the worst ROAS day (and similar bad days) happened.
     *
     * Note: Mercado Ads only exposes DAILY metrics — hour-of-day is not available.
     *
     * @param  list<array<string, mixed>>  $daysWithSpend
     * @param  array<string, mixed>|null  $worstDay
     * @return array<string, mixed>|null
     */
    private function buildWorstRoasPattern(
        array $daysWithSpend,
        ?array $worstDay,
        float $targetRoas,
        float $nearRatio,
    ): ?array {
        if ($worstDay === null || $daysWithSpend === []) {
            return null;
        }

        $costs = array_map(fn ($d) => (float) ($d['cost'] ?? 0), $daysWithSpend);
        $cpcs = array_values(array_filter(array_map(
            fn ($d) => isset($d['cpc']) && $d['cpc'] !== null ? (float) $d['cpc'] : null,
            $daysWithSpend,
        ), fn ($v) => $v !== null && $v > 0));
        sort($costs);
        sort($cpcs);
        $medianCost = $costs[(int) floor((count($costs) - 1) / 2)] ?? 0.0;
        $medianCpc = $cpcs !== []
            ? ($cpcs[(int) floor((count($cpcs) - 1) / 2)] ?? 0.0)
            : 0.0;

        $wCost = (float) ($worstDay['cost'] ?? 0);
        $wRev = (float) ($worstDay['attributed_revenue'] ?? 0);
        $wClicks = (int) ($worstDay['clicks'] ?? 0);
        $wUnits = (float) ($worstDay['units'] ?? 0);
        $wCpc = isset($worstDay['cpc']) && $worstDay['cpc'] !== null
            ? (float) $worstDay['cpc']
            : ($wClicks > 0 ? $wCost / $wClicks : 0.0);
        $wRoas = isset($worstDay['roas']) ? (float) $worstDay['roas'] : null;
        $wDate = (string) ($worstDay['bucket'] ?? $worstDay['label'] ?? '');

        $codes = [];
        $bits = [];
        $rootFactor = 'below_target';

        if ($wCost > 0 && $wRev <= 0) {
            $codes[] = 'zero_revenue';
            $rootFactor = 'zero_revenue';
            $bits[] = 'hubo gasto y ML no atribuyó ventas ese día (clics que no compraron, o atribución en otro día)';
        } elseif ($wClicks >= 20 && $wUnits <= 1) {
            $codes[] = 'low_conversion';
            $rootFactor = 'low_conversion';
            $bits[] = "hubo {$wClicks} clics y casi no convirtieron ({$wUnits} uds): el factor es conversión, no falta de tráfico";
        } elseif ($wClicks > 0 && $wUnits <= 0) {
            $codes[] = 'low_conversion';
            $rootFactor = 'low_conversion';
            $bits[] = "hubo {$wClicks} clics y 0 unidades atribuidas";
        }

        if ($medianCpc > 0 && $wCpc >= $medianCpc * 1.4) {
            $codes[] = 'high_cpc';
            if ($rootFactor === 'below_target') {
                $rootFactor = 'high_cpc';
            }
            $bits[] = 'el clic salió mucho más caro que el típico del periodo';
        }

        if ($medianCost > 0 && $wCost >= $medianCost * 1.6 && ($wRoas === null || $wRoas < $targetRoas * $nearRatio)) {
            $codes[] = 'spend_spike';
            $bits[] = 'fue un día de gasto alto con mal retorno';
        }

        $dowNames = [
            0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miércoles',
            4 => 'jueves', 5 => 'viernes', 6 => 'sábado',
        ];

        $weekdayStats = [];
        for ($d = 0; $d <= 6; $d++) {
            $weekdayStats[$d] = [
                'dow' => $d,
                'name' => $dowNames[$d],
                'days' => 0,
                'bad_days' => 0,
                'spend' => 0.0,
                'revenue' => 0.0,
            ];
        }

        foreach ($daysWithSpend as $d) {
            $date = (string) ($d['bucket'] ?? $d['label'] ?? '');
            if ($date === '') {
                continue;
            }
            try {
                $dow = Carbon::parse($date)->dayOfWeek;
            } catch (\Throwable) {
                continue;
            }
            $cost = (float) ($d['cost'] ?? 0);
            $rev = (float) ($d['attributed_revenue'] ?? 0);
            $roas = $d['roas'] ?? null;
            $isBad = $cost > 0 && (
                $rev <= 0
                || ($roas !== null && (float) $roas < $targetRoas * $nearRatio)
            );
            $weekdayStats[$dow]['days']++;
            $weekdayStats[$dow]['spend'] += $cost;
            $weekdayStats[$dow]['revenue'] += $rev;
            if ($isBad) {
                $weekdayStats[$dow]['bad_days']++;
            }
        }

        $weekdayRows = [];
        $worstWeekday = null;
        $worstWeekdayRate = -1.0;
        foreach ($weekdayStats as $row) {
            if ($row['days'] === 0) {
                continue;
            }
            $rate = $row['bad_days'] / $row['days'];
            $entry = [
                'name' => $row['name'],
                'days' => $row['days'],
                'bad_days' => $row['bad_days'],
                'bad_rate' => round($rate, 2),
                'roas' => $row['spend'] > 0 ? round($row['revenue'] / $row['spend'], 2) : null,
            ];
            $weekdayRows[] = $entry;
            if ($row['days'] >= 1 && $rate > $worstWeekdayRate) {
                $worstWeekdayRate = $rate;
                $worstWeekday = $entry;
            }
        }

        $weekdayHint = null;
        $badDays = array_sum(array_column($weekdayRows, 'bad_days'));
        if ($worstWeekday && $badDays >= 2 && (float) $worstWeekday['bad_rate'] >= 0.4) {
            $codes[] = 'weekday_cluster';
            $weekdayHint = $worstWeekday['name'];
            $bits[] = sprintf(
                'por día de semana, lo más flojo suele ser %s (%d/%d días malos)',
                $weekdayHint,
                (int) $worstWeekday['bad_days'],
                (int) $worstWeekday['days'],
            );
        }

        $tail = array_slice($daysWithSpend, -3);
        if (count($tail) === 3) {
            $roases = array_map(fn ($d) => (float) ($d['roas'] ?? 0), $tail);
            $allBad = collect($tail)->every(
                fn ($d) => (float) ($d['attributed_revenue'] ?? 0) <= 0
                    || ((float) ($d['roas'] ?? 0) < $targetRoas * $nearRatio),
            );
            if ($allBad && $roases[0] >= $roases[1] && $roases[1] >= $roases[2]) {
                $codes[] = 'trailing_decay';
                $bits[] = 'los últimos días vienen empeorando seguidos';
            }
        }

        if ($codes === []) {
            $codes[] = 'below_target';
            $bits[] = 'el retorno quedó lejos de la meta de ganancia';
        }

        $label = match ($rootFactor) {
            'zero_revenue' => 'Gasto sin ventas',
            'low_conversion' => 'Clics sin conversión',
            'high_cpc' => 'Clic caro',
            default => 'Bajo la meta',
        };

        $factorPlain = match ($rootFactor) {
            'zero_revenue' => 'El factor principal no es “un día de la semana”: ese día hubo inversión y cero ventas atribuidas.',
            'low_conversion' => 'El factor principal es conversión baja (llega gente y no compra), no falta de impresiones.',
            'high_cpc' => 'El factor principal es precio del clic muy alto vs el resto del periodo.',
            default => 'El factor principal es ROAS bajo vs tu meta de ganancia.',
        };

        $dateLabel = $wDate !== '' ? Carbon::parse($wDate)->toDateString() : 'ese día';
        $plain = $factorPlain.' Peor día '.$dateLabel.': '.implode('; ', $bits).'.';
        $plain .= ' Día de semana / patrones usan '.$this->describePatternWindow($daysWithSpend).'.';
        $plain .= ' No hay dato por hora: Mercado Ads solo reporta métricas diarias.';

        return [
            'label' => $label,
            'plain' => $plain,
            'root_factor' => $rootFactor,
            'root_factor_plain' => $factorPlain,
            'codes' => array_values(array_unique($codes)),
            'bad_day_count' => (int) $badDays,
            'weekday_hint' => $weekdayHint,
            'weekday_breakdown' => $weekdayRows,
            'hour_available' => false,
            'hour_note' => 'Mercado Ads no expone métricas por hora; solo por día.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $daysWithSpend
     */
    private function describePatternWindow(array $daysWithSpend): string
    {
        $n = count($daysWithSpend);

        return $n > 0
            ? "{$n} días con gasto en la ventana de patrones"
            : 'la ventana de patrones';
    }

    /**
     * Publications that share the same campaign(s) as this item, with spend split.
     * Only items with spend in the window appear (ML does not give a full campaign roster here).
     *
     * @param  list<int>  $connectionIds
     * @param  list<array<string, mixed>>  $campaigns
     * @param  Collection<int, AdSpendDaily>  $dailyRows
     * @return array<string, mixed>
     */
    private function campaignListingsDistribution(
        int $workspaceId,
        string $currentMlItemId,
        array $connectionIds,
        string $from,
        string $to,
        array $campaigns,
        Collection $dailyRows,
    ): array {
        $internalIds = collect($campaigns)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $externalIds = $dailyRows
            ->pluck('external_campaign_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($internalIds === [] && $externalIds === []) {
            return [
                'campaigns' => [],
                'total_cost' => 0.0,
                'listing_count' => 0,
                'other_count' => 0,
                'current_share' => null,
                'listings' => [],
                'note' => 'Sin campañas con gasto en esta ventana para esta publicación.',
            ];
        }

        $rows = AdSpendDaily::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($connectionIds !== [], fn ($q) => $q->whereIn('connection_id', $connectionIds))
            ->where(function ($q) use ($internalIds, $externalIds): void {
                if ($internalIds !== []) {
                    $q->whereIn('ad_campaign_id', $internalIds);
                }
                if ($externalIds !== []) {
                    if ($internalIds !== []) {
                        $q->orWhereIn('external_campaign_id', $externalIds);
                    } else {
                        $q->whereIn('external_campaign_id', $externalIds);
                    }
                }
            })
            ->selectRaw('
                ml_item_id,
                SUM(cost) as cost,
                SUM(clicks) as clicks,
                SUM(prints) as prints,
                SUM(COALESCE(total_amount, 0)) as attributed_revenue
            ')
            ->groupBy('ml_item_id')
            ->orderByDesc('cost')
            ->limit(100)
            ->get();

        $itemIds = $rows->pluck('ml_item_id')->map(fn ($id) => (string) $id)->all();
        $listingsByItem = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('external_item_id', $itemIds)
            ->when($connectionIds !== [], fn ($q) => $q->whereIn('connection_id', $connectionIds))
            ->get(['external_item_id', 'title', 'permalink', 'product_id', 'status'])
            ->keyBy(fn (ChannelListing $l) => (string) $l->external_item_id);

        $totalCost = (float) $rows->sum('cost');
        $listingsPayload = $rows->map(function ($row) use ($listingsByItem, $currentMlItemId, $totalCost) {
            $itemId = (string) $row->ml_item_id;
            $listing = $listingsByItem->get($itemId);
            $cost = (float) $row->cost;
            $revenue = (float) $row->attributed_revenue;
            $clicks = (int) $row->clicks;

            return [
                'ml_item_id' => $itemId,
                'title' => $listing?->title ?: $itemId,
                'permalink' => $listing?->permalink,
                'product_id' => $listing?->product_id ? (int) $listing->product_id : null,
                'status' => $listing?->status,
                'is_current' => $itemId === $currentMlItemId,
                'cost' => round($cost, 2),
                'clicks' => $clicks,
                'prints' => (int) $row->prints,
                'attributed_revenue' => round($revenue, 2),
                'roas' => $cost > 0 ? round($revenue / $cost, 4) : null,
                'acos' => $revenue > 0 ? round($cost / $revenue, 6) : null,
                'cpc' => $clicks > 0 ? round($cost / $clicks, 4) : null,
                'share_of_spend' => $totalCost > 0 ? round($cost / $totalCost, 4) : null,
            ];
        })->values()->all();

        $currentShare = collect($listingsPayload)->firstWhere('is_current')['share_of_spend'] ?? null;
        $otherCount = max(0, count($listingsPayload) - 1);

        return [
            'campaigns' => collect($campaigns)
                ->map(fn (array $c) => [
                    'id' => $c['id'] ?? null,
                    'name' => $c['name'] ?? null,
                    'status' => $c['status'] ?? null,
                ])
                ->values()
                ->all(),
            'total_cost' => round($totalCost, 2),
            'listing_count' => count($listingsPayload),
            'other_count' => $otherCount,
            'current_share' => $currentShare,
            'listings' => $listingsPayload,
            'note' => 'Solo aparecen publicaciones con gasto de ads en las mismas campañas y en esta ventana. Si una publicación está en la campaña pero no gastó, no sale aquí.',
        ];
    }
}
