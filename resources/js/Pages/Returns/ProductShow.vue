<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import DashboardChart from '@/Components/Dashboard/DashboardChart.vue';
import OrderDetailSlideOver from '@/Components/Orders/OrderDetailSlideOver.vue';
import ReturnRiskBadge from '@/Components/Returns/ReturnRiskBadge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDateTime } from '@/lib/utils';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { LineChart, PieChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, MarkLineComponent } from 'echarts/components';
import { ArrowLeft, ClipboardList } from 'lucide-vue-next';

use([
    CanvasRenderer,
    LineChart,
    PieChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    MarkLineComponent,
]);

const props = defineProps<{
    period: { preset: string; from: string; to: string; label: string };
    product_key: string;
    detail: Record<string, any>;
    action: Record<string, any> | null;
    clusters: Array<Record<string, any>>;
    action_statuses: string[];
}>();

const product = computed(() => props.detail.product || {});
const brand = '#0f766e';

const orderSlideOpen = ref(false);
const selectedOrderId = ref<number | null>(null);

function openOrder(orderId: number | null | undefined) {
    if (!orderId) return;
    selectedOrderId.value = Number(orderId);
    orderSlideOpen.value = true;
}

function closeOrder() {
    orderSlideOpen.value = false;
    selectedOrderId.value = null;
}

const form = useForm({
    status: props.action?.status || 'review',
    notes: '',
    action_taken: props.action?.action_taken || '',
    assigned_user_id: props.action?.assigned_user_id || null,
});

function submitAction() {
    form.post(route('returns.products.actions', props.product_key), { preserveScroll: true });
}

function ratePct(rate: number) {
    return `${((rate || 0) * 100).toFixed(1)}%`;
}

function outcomeLabel(outcome: string | null) {
    switch (outcome) {
        case 'returned':
            return 'Devuelto';
        case 'refunded':
            return 'Reembolsado';
        case 'partial_refunded':
            return 'Parcial';
        default:
            return outcome || '—';
    }
}

const seriesChart = computed(() => {
    const series = props.detail.series || [];
    const hist = props.detail.historical_avg != null
        ? +(props.detail.historical_avg * 100).toFixed(2)
        : null;

    return {
        color: [brand, '#0284c7', '#d97706'],
        tooltip: {
            trigger: 'axis',
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: '#e2e8f0',
            textStyle: { fontSize: 11, color: '#0f172a' },
        },
        legend: {
            top: 0,
            right: 0,
            textStyle: { fontSize: 10, color: '#64748b' },
            itemWidth: 10,
            itemHeight: 8,
        },
        grid: { left: 8, right: 8, top: 28, bottom: 8, containLabel: true },
        xAxis: {
            type: 'category',
            data: series.map((s: any) => s.date),
            axisLabel: { color: '#94a3b8', fontSize: 9 },
            axisLine: { lineStyle: { color: '#e2e8f0' } },
            axisTick: { show: false },
        },
        yAxis: [
            {
                type: 'value',
                splitLine: { lineStyle: { color: '#f1f5f9' } },
                axisLabel: { color: '#94a3b8', fontSize: 9, formatter: '{value}%' },
            },
            {
                type: 'value',
                splitLine: { show: false },
                axisLabel: { color: '#94a3b8', fontSize: 9 },
            },
        ],
        series: [
            {
                name: 'Tasa %',
                type: 'line',
                smooth: true,
                showSymbol: false,
                data: series.map((s: any) => +((s.return_rate || 0) * 100).toFixed(2)),
                markLine: hist != null
                    ? {
                        symbol: 'none',
                        lineStyle: { color: '#94a3b8', type: 'dashed', width: 1 },
                        label: { formatter: 'Histórico', fontSize: 9, color: '#64748b' },
                        data: [{ yAxis: hist }],
                    }
                    : undefined,
            },
            {
                name: 'Vendidas',
                type: 'line',
                yAxisIndex: 1,
                smooth: true,
                showSymbol: false,
                data: series.map((s: any) => s.units_sold),
            },
            {
                name: 'Devueltas',
                type: 'line',
                yAxisIndex: 1,
                smooth: true,
                showSymbol: false,
                data: series.map((s: any) => s.returned_units),
            },
        ],
    };
});

const reasonChart = computed(() => ({
    color: [brand, '#0284c7', '#d97706', '#7c3aed', '#dc2626', '#94a3b8'],
    tooltip: {
        trigger: 'item',
        backgroundColor: 'rgba(255,255,255,0.96)',
        borderColor: '#e2e8f0',
        textStyle: { fontSize: 11, color: '#0f172a' },
    },
    legend: {
        bottom: 0,
        type: 'scroll',
        textStyle: { fontSize: 10, color: '#64748b' },
        itemWidth: 8,
        itemHeight: 8,
    },
    series: [
        {
            type: 'pie',
            radius: ['42%', '68%'],
            center: ['50%', '46%'],
            itemStyle: { borderRadius: 4, borderColor: '#fff', borderWidth: 2 },
            label: { show: false },
            data: (props.detail.reasons || []).map((r: any) => ({ name: r.label, value: r.count })),
        },
    ],
}));
</script>

<template>
    <Head :title="product.name || 'Análisis de devoluciones'" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="mx-auto max-w-7xl space-y-3 px-4 sm:px-6 lg:px-8">
                <PageHeader
                    :title="product.name || 'Producto'"
                    :description="`${period.label} · ${product.sku || 'sin SKU'} · ${product.ml_item_id || '—'} · ${product.category || 'Sin categoría'}`"
                >
                    <template #actions>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Link :href="route('returns.index', { period: period.preset })">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-7 px-2.5 text-[11px]"
                                >
                                    <ArrowLeft class="mr-1 size-3.5" />
                                    Devoluciones
                                </Button>
                            </Link>
                            <Link
                                :href="route('returns.items.index', {
                                    period: period.preset,
                                    product_id: product.product_id || undefined,
                                    q: product.ml_item_id || product.sku || undefined,
                                })"
                            >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-7 px-2.5 text-[11px]"
                                >
                                    <ClipboardList class="mr-1 size-3.5" />
                                    Casos
                                </Button>
                            </Link>
                        </div>
                    </template>
                </PageHeader>

                <div class="flex flex-wrap items-center gap-2">
                    <img
                        v-if="product.image"
                        :src="product.image"
                        alt=""
                        class="size-12 rounded-lg object-cover ring-1 ring-slate-200/80"
                    >
                    <ReturnRiskBadge
                        :level="product.risk_level"
                        :score="product.risk_score"
                    />
                    <Badge
                        variant="outline"
                        class="text-[10px]"
                    >
                        Confianza: {{ product.confidence || '—' }}
                    </Badge>
                    <Badge
                        v-if="product.insufficient_sample"
                        variant="muted"
                        class="text-[10px]"
                    >
                        Muestra insuficiente
                    </Badge>
                    <ConnectionChip
                        v-if="product.connection"
                        :connection="product.connection"
                    />
                </div>

                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Ventas (u)
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ product.units_sold ?? 0 }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            {{ product.return_count ?? 0 }} devoluciones · {{ product.returned_units ?? 0 }} u
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Tasa de devolución
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ ratePct(product.return_rate) }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            Histórico: {{ ratePct(detail.historical_avg || 0) }}
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            $ Devuelto
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText :amount="product.returned_amount" />
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            Pérdida est.
                            <MoneyText :amount="product.estimated_loss" />
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Motivo dominante
                        </p>
                        <p class="mt-1 text-lg font-semibold tracking-tight text-slate-900">
                            {{ product.dominant_reason_label || '—' }}
                        </p>
                        <p
                            v-if="product.top_variant_label"
                            class="mt-1 truncate text-[10px] text-muted-foreground"
                        >
                            Variante top: {{ product.top_variant_label }}
                        </p>
                    </Card>
                </div>

                <Card
                    class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                    content-class="space-y-3 p-3"
                >
                    <h2 class="text-[13px] font-semibold tracking-tight text-slate-900">
                        Por qué lo devuelven
                    </h2>
                    <p class="text-[13px] leading-relaxed text-slate-700">
                        {{ product.narrative || 'Aún no hay suficiente texto de compradores para un resumen.' }}
                    </p>
                    <div
                        v-if="product.risk_factors?.length"
                        class="space-y-1.5 border-t border-slate-100 pt-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Factores del Risk Score
                        </p>
                        <ul class="space-y-1">
                            <li
                                v-for="f in product.risk_factors"
                                :key="f.key"
                                class="text-[11px] text-slate-600"
                            >
                                <span class="font-medium text-slate-800">{{ f.label }}</span>
                                <span class="text-muted-foreground"> ({{ f.points }} pts)</span>
                                — {{ f.detail }}
                            </li>
                        </ul>
                    </div>
                </Card>

                <div class="grid gap-3 lg:grid-cols-2">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight">
                            Evolución
                        </h3>
                        <DashboardChart
                            v-if="(detail.series || []).length"
                            :option="seriesChart"
                            height="260px"
                        />
                        <p
                            v-else
                            class="py-12 text-center text-sm text-muted-foreground"
                        >
                            Sin serie diaria todavía.
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight">
                            Motivos
                        </h3>
                        <DashboardChart
                            v-if="(detail.reasons || []).length"
                            :option="reasonChart"
                            height="260px"
                        />
                        <p
                            v-else
                            class="py-12 text-center text-sm text-muted-foreground"
                        >
                            Sin motivos en el periodo.
                        </p>
                    </Card>
                </div>

                <Card
                    class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                    content-class="p-0"
                >
                    <div class="border-b border-slate-100 px-3 py-2.5">
                        <h3 class="text-[13px] font-semibold tracking-tight">
                            Variantes
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50/80 text-left text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2.5">Variante</th>
                                    <th class="px-3 py-2.5">Ventas</th>
                                    <th class="px-3 py-2.5">Dev.</th>
                                    <th class="px-3 py-2.5">Tasa</th>
                                    <th class="px-3 py-2.5">Alerta</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="v in detail.variants || []"
                                    :key="`${v.variant_id}-${v.ml_variation_id}`"
                                    class="border-t border-slate-100"
                                >
                                    <td class="px-3 py-2.5 font-medium">{{ v.label }}</td>
                                    <td class="px-3 py-2.5 tabular-nums">{{ v.units_sold }}</td>
                                    <td class="px-3 py-2.5 tabular-nums">{{ v.returned_units }}</td>
                                    <td class="px-3 py-2.5 font-medium tabular-nums">
                                        {{ ratePct(v.return_rate) }}
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <Badge
                                            v-if="v.is_outlier"
                                            variant="danger"
                                            class="text-[10px]"
                                        >
                                            {{ v.outlier_multiplier }}x peers
                                        </Badge>
                                        <span
                                            v-else
                                            class="text-xs text-muted-foreground"
                                        >—</span>
                                    </td>
                                </tr>
                                <tr v-if="!(detail.variants || []).length">
                                    <td
                                        colspan="5"
                                        class="px-3 py-8 text-center text-sm text-muted-foreground"
                                    >
                                        Sin desglose por variante.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <div class="grid gap-3 lg:grid-cols-2">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="space-y-2 p-3"
                    >
                        <h3 class="text-[13px] font-semibold tracking-tight">
                            Comentarios frecuentes
                        </h3>
                        <div
                            v-for="c in clusters"
                            :key="c.cluster_key"
                            class="rounded-lg border border-slate-100 bg-slate-50/50 px-3 py-2"
                        >
                            <p class="text-[12px] text-slate-700">
                                <span class="font-semibold text-slate-900">{{ c.occurrence_count }}</span>
                                compradores: “{{ c.representative_phrase }}”
                            </p>
                        </div>
                        <p
                            v-if="!clusters.length"
                            class="py-4 text-center text-sm text-muted-foreground"
                        >
                            Sin comentarios agrupables todavía.
                        </p>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="space-y-2 p-3"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-[13px] font-semibold tracking-tight">
                                Casos individuales
                            </h3>
                            <span class="text-[10px] text-muted-foreground">
                                {{ (detail.cases || []).length }} recientes
                            </span>
                        </div>
                        <div
                            v-for="c in detail.cases || []"
                            :key="c.id"
                            class="rounded-lg border border-slate-100 px-3 py-2 transition-colors hover:bg-slate-50/80"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <Link
                                    :href="route('returns.items.show', c.id)"
                                    class="text-[12px] font-medium text-slate-900 hover:text-brand hover:underline"
                                >
                                    {{ c.inferred_reason_label || 'Sin motivo' }}
                                </Link>
                                <div class="flex items-center gap-1.5">
                                    <Badge
                                        variant="outline"
                                        class="text-[10px]"
                                    >
                                        {{ outcomeLabel(c.outcome) }}
                                    </Badge>
                                    <Badge
                                        variant="muted"
                                        class="text-[10px]"
                                    >
                                        {{ c.analysis_source || '—' }}
                                    </Badge>
                                </div>
                            </div>
                            <p class="mt-1 line-clamp-2 text-[11px] text-muted-foreground">
                                {{ c.analysis_summary || 'Sin análisis' }}
                            </p>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[10px] text-muted-foreground">
                                <span>{{ formatDateTime(c.opened_at) }}</span>
                                <span v-if="c.days_to_return != null">· {{ c.days_to_return }}d</span>
                                <button
                                    v-if="c.order_id"
                                    type="button"
                                    class="font-semibold text-brand hover:underline"
                                    @click="openOrder(c.order_id)"
                                >
                                    Ver orden
                                </button>
                            </div>
                        </div>
                        <p
                            v-if="!(detail.cases || []).length"
                            class="py-4 text-center text-sm text-muted-foreground"
                        >
                            Sin casos en el periodo.
                        </p>
                    </Card>
                </div>

                <Card
                    class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                    content-class="space-y-3 p-3"
                >
                    <h3 class="text-[13px] font-semibold tracking-tight">
                        Seguimiento
                    </h3>
                    <form
                        class="grid gap-3 sm:grid-cols-2"
                        @submit.prevent="submitAction"
                    >
                        <div class="sm:col-span-2 sm:max-w-xs">
                            <label class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                Estado
                            </label>
                            <select
                                v-model="form.status"
                                class="mt-1 h-9 w-full rounded-lg border border-slate-200 px-2.5 text-[12px]"
                            >
                                <option
                                    v-for="s in action_statuses"
                                    :key="s"
                                    :value="s"
                                >
                                    {{ s }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                Nota
                            </label>
                            <textarea
                                v-model="form.notes"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-2 text-[12px]"
                                rows="3"
                            />
                        </div>
                        <div>
                            <label class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                Acción realizada
                            </label>
                            <textarea
                                v-model="form.action_taken"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-2 text-[12px]"
                                rows="3"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <Button
                                type="submit"
                                size="sm"
                                class="h-8 text-[11px]"
                                :disabled="form.processing"
                            >
                                Guardar seguimiento
                            </Button>
                        </div>
                    </form>
                    <div
                        v-if="action?.notes_history?.length"
                        class="space-y-1.5 border-t border-slate-100 pt-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Historial
                        </p>
                        <div
                            v-for="n in action.notes_history"
                            :key="n.id"
                            class="text-[11px] text-slate-600"
                        >
                            <span class="font-medium text-slate-800">{{ n.event_type }}</span>: {{ n.body }}
                        </div>
                    </div>
                </Card>
            </div>
        </div>

        <OrderDetailSlideOver
            :show="orderSlideOpen"
            :order-id="selectedOrderId"
            :connection="product.connection"
            initial-tab="claims"
            @close="closeOrder"
        />
    </AuthenticatedLayout>
</template>
