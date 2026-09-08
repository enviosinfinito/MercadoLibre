<script setup lang="ts">
import { computed } from 'vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';

export interface BreakdownLine {
    event_type?: string;
    label?: string;
    amount?: string | number | null;
    provenance?: Record<string, unknown> | null;
}

export interface ProfitSnapshotLike {
    stage?: string;
    revenue_amount?: string | number | null;
    fees_amount?: string | number | null;
    cogs_amount?: string | number | null;
    profit_amount?: string | number | null;
    currency_code?: string | null;
    is_incomplete?: boolean;
    payload?: {
        taxes_retention_total?: string | number | null;
        marketplace_net_amount?: string | number | null;
        net_received_amount?: string | number | null;
        sale_reversed?: boolean;
        post_sale_outcome?: string | null;
        breakdown?: {
            fees?: BreakdownLine[];
            fees_total?: string | number | null;
            taxes_retention?: BreakdownLine[];
            taxes_retention_total?: string | number | null;
            refunds?: BreakdownLine[];
            marketplace_net?: string | number | null;
        };
        [key: string]: unknown;
    } | null;
}

const props = defineProps<{
    snapshot?: ProfitSnapshotLike | null;
    postSaleOutcome?: string | null;
    mpBalance?: string | number | null;
    mpBalanceStatus?: string | null;
    mpBalanceReserved?: boolean;
    hasBuyerShipping?: boolean;
}>();

const currency = computed(() => props.snapshot?.currency_code ?? 'MXN');
const incomplete = computed(() => Boolean(props.snapshot?.is_incomplete));

const breakdown = computed(() => props.snapshot?.payload?.breakdown ?? null);

const feeLines = computed(() => breakdown.value?.fees ?? []);
const taxLines = computed(() => breakdown.value?.taxes_retention ?? []);
const refundLines = computed(() => breakdown.value?.refunds ?? []);

const saleReversed = computed(() => {
    if (props.snapshot?.payload?.sale_reversed) return true;
    const outcome = props.postSaleOutcome ?? props.snapshot?.payload?.post_sale_outcome;
    return outcome === 'returned' || outcome === 'refunded';
});

const partialRefundNote = computed(
    () => (props.postSaleOutcome ?? props.snapshot?.payload?.post_sale_outcome) === 'partial_refunded',
);

const subtitle = computed(() => {
    if (saleReversed.value) {
        return 'Venta revertida por devolución/reembolso';
    }
    if (partialRefundNote.value) {
        return 'Reembolso parcial · monto no aplicado al P&L expected';
    }
    return `Utilidad esperada · ${props.snapshot?.stage ?? 'expected'}`;
});

const feesTotal = computed(
    () =>
        breakdown.value?.fees_total ??
        props.snapshot?.fees_amount ??
        '0',
);

const taxesTotal = computed(
    () =>
        breakdown.value?.taxes_retention_total ??
        props.snapshot?.payload?.taxes_retention_total ??
        '0',
);

/** What Mercado Libre deposits after fees + taxes (before COGS). */
const marketplaceNet = computed(() => {
    const fromPayload =
        props.snapshot?.payload?.net_received_amount ??
        props.snapshot?.payload?.marketplace_net_amount ??
        breakdown.value?.marketplace_net ??
        null;
    if (fromPayload != null && fromPayload !== '') {
        return fromPayload;
    }

    const rev = Number(props.snapshot?.revenue_amount ?? 0);
    const fees = Number(feesTotal.value ?? 0);
    const taxes = Number(taxesTotal.value ?? 0);
    if (!Number.isFinite(rev)) return null;
    return (rev - fees - taxes).toFixed(6);
});

function provenanceHint(line: BreakdownLine): string | null {
    const p = line.provenance;
    if (!p) return null;
    const rate = p.rate != null ? `${Number(p.rate) * 100}%` : null;
    const source = typeof p.source === 'string' ? p.source : null;
    if (rate && source === 'estimate') return `Est. ${rate}`;
    if (source === 'mercadolibre_sale_fee' || source === 'mercadolibre_collections_marketplace_fee') {
        return 'Comisión ML real';
    }
    if (source === 'mercadolibre_shipment_costs_sender') return 'Envío seller (costs API)';
    if (source === 'mercadolibre_shipping_cost' || source === 'mercadolibre_shipment_base_cost') {
        return 'Envío seller (base_cost)';
    }
    if (source === 'mercadolibre_shipment_list_cost') return 'Envío seller (list_cost)';
    if (source === 'mercadolibre_shipment_option_cost') return 'Envío (option.cost)';
    if (source === 'mercadolibre_collections_residual_split') {
        return rate ? `Residual collections · ${rate}` : 'Residual collections';
    }
    if (source === 'mercadolibre_collections_residual') return 'Residual real (collections)';
    if (source === 'estimate_mx_ml_ui') {
        return rate ? `Estimado base s/IVA · ${rate}` : 'Estimado (sin collections)';
    }
    if (source === 'product_ads_item_daily' || p.model === 'blended_acos' || p.model === 'blended_acos_ml_rate') {
        return rate
            ? `Ads estimado (reparto ítem+día) · ${rate}`
            : 'Ads estimado (reparto ítem+día)';
    }
    if (typeof p.note === 'string' && p.note.length < 48) return p.note;
    return null;
}

function lineLabel(line: BreakdownLine): string {
    return line.label ?? line.event_type ?? '—';
}

function lineTitle(line: BreakdownLine): string {
    return [lineLabel(line), provenanceHint(line)].filter(Boolean).join(' · ');
}
</script>

<template>
    <Card content-class="p-3">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 class="text-[13px] font-semibold tracking-tight text-slate-900">
                    Resumen financiero
                </h3>
                <p class="truncate text-[11px] text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>
            <Badge
                :variant="saleReversed ? 'danger' : incomplete ? 'warning' : 'success'"
                class="h-5 shrink-0 px-1.5 text-[10px]"
            >
                {{ saleReversed ? 'Revertida' : incomplete ? 'Incompleto' : 'Completo' }}
            </Badge>
        </div>

        <div v-if="!snapshot" class="mt-2 text-xs text-muted-foreground">
            Sin snapshot de utilidad disponible.
        </div>

        <!-- Cascada: ingresos → deducciones → neto ML → COGS → utilidad -->
        <div v-else class="mt-2.5 space-y-1.5">
            <p
                v-if="saleReversed"
                class="rounded-md border border-rose-200 bg-rose-50/80 px-2 py-1.5 text-[11px] text-rose-900"
            >
                La salida de pago anula los ingresos expected. Comisiones y retenciones se
                revirtieron aquí.
            </p>
            <p
                v-else-if="partialRefundNote"
                class="rounded-md border border-amber-200 bg-amber-50/80 px-2 py-1.5 text-[11px] text-amber-950"
            >
                Reembolso parcial en el reclamo; el monto exacto aún no se sincroniza al P&L.
            </p>

            <!-- 1. Ingresos -->
            <div class="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-2.5 py-1.5">
                <span class="text-[11px] text-muted-foreground">Ingresos (neto)</span>
                <MoneyText
                    class="text-[12px] font-semibold tabular-nums"
                    :amount="snapshot.revenue_amount"
                    :currency="currency"
                />
            </div>

            <!-- 1b. Reembolsos (si aplica) -->
            <div v-if="refundLines.length" class="rounded-md border border-rose-100 bg-rose-50/40 px-2.5 py-1.5">
                <p class="text-[9px] font-semibold uppercase tracking-wide text-rose-700">
                    Salida de pago
                </p>
                <ul class="mt-1 space-y-0.5">
                    <li
                        v-for="(line, idx) in refundLines"
                        :key="`${line.event_type}-${idx}`"
                        class="flex items-center justify-between gap-2"
                    >
                        <p
                            class="min-w-0 truncate text-[11px] text-slate-700"
                            :title="lineTitle(line)"
                        >
                            {{ lineLabel(line) }}
                        </p>
                        <MoneyText
                            class="shrink-0 text-[11px] text-rose-800"
                            :amount="line.amount"
                            :currency="currency"
                        />
                    </li>
                </ul>
            </div>

            <!-- 2. Comisiones -->
            <div class="rounded-md border border-slate-200/80 px-2.5 py-1.5">
                <p class="text-[9px] font-semibold uppercase tracking-wide text-slate-500">
                    − Comisiones / fees
                </p>
                <ul v-if="feeLines.length" class="mt-1 space-y-0.5">
                    <li
                        v-for="(line, idx) in feeLines"
                        :key="`${line.event_type}-${idx}`"
                        class="flex items-center justify-between gap-2"
                    >
                        <p
                            class="min-w-0 truncate text-[11px] text-slate-700"
                            :title="lineTitle(line)"
                        >
                            {{ lineLabel(line) }}
                            <span
                                v-if="provenanceHint(line)"
                                class="text-muted-foreground"
                            > · {{ provenanceHint(line) }}</span>
                        </p>
                        <MoneyText
                            class="shrink-0 text-[11px] tabular-nums text-slate-700"
                            :amount="line.amount"
                            :currency="currency"
                        />
                    </li>
                </ul>
                <p v-else class="mt-1 text-[11px] text-muted-foreground">Sin comisiones</p>
                <div class="mt-1 flex items-center justify-between border-t border-slate-100 pt-1 text-[11px] font-medium">
                    <span class="text-slate-600">Subtotal</span>
                    <MoneyText :amount="feesTotal" :currency="currency" />
                </div>
            </div>

            <!-- 3. Retención -->
            <div class="rounded-md border border-amber-200/80 bg-amber-50/40 px-2.5 py-1.5">
                <p class="text-[9px] font-semibold uppercase tracking-wide text-amber-800">
                    − Retención del gobierno
                </p>
                <ul v-if="taxLines.length" class="mt-1 space-y-0.5">
                    <li
                        v-for="(line, idx) in taxLines"
                        :key="`${line.event_type}-${idx}`"
                        class="flex items-center justify-between gap-2"
                    >
                        <p
                            class="min-w-0 truncate text-[11px] text-slate-700"
                            :title="lineTitle(line)"
                        >
                            {{ lineLabel(line) }}
                            <span
                                v-if="provenanceHint(line)"
                                class="text-amber-800/70"
                            > · {{ provenanceHint(line) }}</span>
                        </p>
                        <MoneyText
                            class="shrink-0 text-[11px] tabular-nums text-slate-700"
                            :amount="line.amount"
                            :currency="currency"
                        />
                    </li>
                </ul>
                <p v-else class="mt-1 text-[11px] text-amber-900/70">Sin retenciones</p>
                <div class="mt-1 flex items-center justify-between border-t border-amber-200/70 pt-1 text-[11px] font-semibold text-amber-950">
                    <span>Subtotal</span>
                    <MoneyText :amount="taxesTotal" :currency="currency" />
                </div>
            </div>

            <!-- 4. Neto ML -->
            <div
                v-if="marketplaceNet != null"
                class="flex items-center justify-between gap-2 rounded-md border border-emerald-200/80 px-2.5 py-1.5"
            >
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-emerald-900">
                        Neto del cobro (antes de COGS)
                    </p>
                    <p class="truncate text-[10px] text-emerald-800/75">
                        Tras comisión, envío e impuestos · antes de COGS
                    </p>
                    <p
                        v-if="mpBalance != null && mpBalance !== ''"
                        class="mt-1 text-[10px] leading-snug text-emerald-800/90"
                    >
                        Eso es el neto de la <span class="font-semibold">venta</span>.
                        En saldo MP:
                        <MoneyText :amount="mpBalance" :currency="currency" class="font-semibold" />
                        <span v-if="mpBalanceReserved || mpBalanceStatus === 'reserved' || mpBalanceStatus === 'in_mediation'">
                            (retenido por mediación)
                        </span>
                        <span v-else-if="hasBuyerShipping">
                            (incluye envío que pagó el comprador)
                        </span>
                    </p>
                </div>
                <MoneyText
                    class="shrink-0 text-[12px] font-semibold tabular-nums text-emerald-950"
                    :amount="marketplaceNet"
                    :currency="currency"
                />
            </div>

            <!-- 5. COGS -->
            <div class="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-2.5 py-1.5">
                <span class="text-[11px] text-muted-foreground">− Costo de mercancía (COGS)</span>
                <MoneyText
                    class="text-[12px] font-semibold tabular-nums"
                    :amount="snapshot.cogs_amount"
                    :currency="currency"
                />
            </div>

            <!-- 6. Utilidad -->
            <div class="flex items-center justify-between gap-2 rounded-md bg-brand-muted px-2.5 py-2">
                <span class="text-[12px] font-semibold text-brand">= Utilidad esperada</span>
                <MoneyText
                    :amount="snapshot.profit_amount"
                    :currency="currency"
                    :incomplete="incomplete"
                    class="text-[13px] font-semibold text-brand"
                />
            </div>
        </div>
    </Card>
</template>
