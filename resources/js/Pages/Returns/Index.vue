<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import DashboardChart from '@/Components/Dashboard/DashboardChart.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import ReturnRiskBadge from '@/Components/Returns/ReturnRiskBadge.vue';
import ReturnSparkline from '@/Components/Returns/ReturnSparkline.vue';
import ReturnProductSlideOver from '@/Components/Returns/ReturnProductSlideOver.vue';
import ReturnsSearchAndFilters from '@/Components/Returns/SearchAndFilters.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { connectionFilterLabel } from '@/lib/connectionLabel';
import {
    connectionSurfaceStyle,
    resolveConnectionColor,
} from '@/lib/connectionColor';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart, LineChart, ScatterChart, PieChart, HeatmapChart } from 'echarts/charts';
import {
    GridComponent,
    TooltipComponent,
    LegendComponent,
    VisualMapComponent,
} from 'echarts/components';
import { ClipboardList, RotateCcw } from 'lucide-vue-next';

use([
    CanvasRenderer,
    BarChart,
    LineChart,
    ScatterChart,
    PieChart,
    HeatmapChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    VisualMapComponent,
]);

interface ConnectionRow {
    id: number;
    provider: string;
    external_user_id?: string | null;
    display_name?: string | null;
    color?: string | null;
    status?: string;
}

interface ProductRow {
    product_id: number | null;
    ml_item_id: string | null;
    sku: string | null;
    name: string;
    image: string | null;
    category: string | null;
    connection_id?: number | null;
    connection?: ConnectionRow | null;
    units_sold: number;
    returned_units: number;
    return_count: number;
    return_rate: number;
    returned_amount: number;
    estimated_loss: number;
    dominant_reason_label: string | null;
    top_variant_label: string | null;
    sparkline: number[];
    risk_score: number;
    risk_level: string;
    confidence: string;
    narrative: string | null;
    insufficient_sample: boolean;
}

const props = defineProps<{
    period: { preset: string; from: string; to: string; label: string; key: string };
    filters: Record<string, any>;
    connections: ConnectionRow[];
    kpis: Record<string, any>;
    insights: Array<{ id: number; title: string; body: string; severity: string }>;
    attention_products: ProductRow[];
    products: ProductRow[];
    reasons: Array<{ label: string; count: number; share: number; amount: number; products_affected: number }>;
    categories: Array<{ category: string; return_rate: number; returned_amount: number; products: number }>;
    pareto: { items: Array<{ name: string; amount: number; cumulative_share: number }>; cutoff_index: number };
    scatter: Array<{ name: string; x: number; y: number; z: number; risk_level: string }>;
    heatmap: { products: string[]; weeks: string[]; matrix: Array<Array<number | null>> };
    days_to_return: Array<{ bucket: string; count: number; share: number }>;
    logistics: Array<Record<string, any>>;
    geo: Array<Record<string, any>>;
    comparison: Record<string, any>;
    empty_hint: string | null;
}>();

const activeTab = computed(() => props.filters.tab || 'products');

const allConnectionIds = computed(() => props.connections.map((c) => c.id));

const selectedConnectionIds = computed(() => {
    const fromFilters = props.filters?.connection_ids ?? [];
    if (!fromFilters.length) return allConnectionIds.value;
    return fromFilters.map((id: number | string) => Number(id));
});

const isFilteringConnections = computed(
    () =>
        props.connections.length > 1 &&
        (props.filters?.connection_ids?.length ?? 0) > 0 &&
        selectedConnectionIds.value.length < props.connections.length,
);

function isConnectionSelected(id: number): boolean {
    return selectedConnectionIds.value.includes(id);
}

function queryBase() {
    return {
        period: props.filters.period || props.period.preset || 'last_30_days',
        q: props.filters.q || undefined,
        sort: props.filters.sort || 'risk_score',
        anomalies_only: props.filters.anomalies_only ? 1 : undefined,
        above_historical: props.filters.above_historical ? 1 : undefined,
        from: props.filters.from || undefined,
        to: props.filters.to || undefined,
        tab: activeTab.value !== 'products' ? activeTab.value : undefined,
    };
}

function toggleConnection(id: number): void {
    if (props.connections.length <= 1) return;

    const current = [...selectedConnectionIds.value];
    const idx = current.indexOf(id);
    if (idx >= 0) {
        if (current.length <= 1) return;
        current.splice(idx, 1);
    } else {
        current.push(id);
    }
    current.sort((a, b) => a - b);

    const allSelected =
        current.length === allConnectionIds.value.length &&
        allConnectionIds.value.every((cid) => current.includes(cid));

    router.get(
        route('returns.index'),
        {
            ...queryBase(),
            ...(allSelected ? {} : { connection_ids: current }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setTab(next: string) {
    router.get(
        route('returns.index'),
        {
            ...queryBase(),
            tab: next === 'products' ? undefined : next,
            ...(isFilteringConnections.value
                ? { connection_ids: selectedConnectionIds.value }
                : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/** Mantiene chips de conexión y tab al aplicar búsqueda/filtros del toolbar. */
function preserveListContext(params: Record<string, unknown>) {
    if (isFilteringConnections.value) {
        params.connection_ids = selectedConnectionIds.value;
    }
    if (activeTab.value && activeTab.value !== 'products') {
        params.tab = activeTab.value;
    }
}

function productKey(row: ProductRow) {
    return row.product_id != null ? String(row.product_id) : `ml:${row.ml_item_id}`;
}

const productSlideOpen = ref(false);
const selectedProductKey = ref<string | null>(null);

function openProduct(row: ProductRow) {
    selectedProductKey.value = productKey(row);
    productSlideOpen.value = true;
}

function closeProduct() {
    productSlideOpen.value = false;
    selectedProductKey.value = null;
}

function ratePct(rate: number) {
    return `${((rate || 0) * 100).toFixed(1)}%`;
}

function deltaClass(delta: number | null | undefined) {
    if (delta == null || delta === 0) return 'text-muted-foreground';
    return delta > 0 ? 'text-red-600' : 'text-emerald-600';
}

function formatDelta(delta: number | null | undefined) {
    if (delta == null) return 'vs periodo ant.';
    const sign = delta > 0 ? '+' : '';
    return `${sign}${Number(delta).toFixed(1)}% vs periodo ant.`;
}

const brand = '#0f766e';

const reasonChartOption = computed(() => ({
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
            data: props.reasons.map((r) => ({ name: r.label, value: r.count })),
        },
    ],
}));

const paretoChartOption = computed(() => ({
    color: [brand, '#f59e0b'],
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
    grid: { left: 8, right: 8, top: 28, bottom: 48, containLabel: true },
    xAxis: {
        type: 'category',
        data: props.pareto.items.slice(0, 14).map((i) =>
            i.name.length > 18 ? `${i.name.slice(0, 16)}…` : i.name,
        ),
        axisLabel: { color: '#94a3b8', fontSize: 9, rotate: 28 },
        axisLine: { lineStyle: { color: '#e2e8f0' } },
        axisTick: { show: false },
    },
    yAxis: [
        {
            type: 'value',
            splitLine: { lineStyle: { color: '#f1f5f9' } },
            axisLabel: { color: '#94a3b8', fontSize: 9 },
        },
        {
            type: 'value',
            max: 100,
            splitLine: { show: false },
            axisLabel: { color: '#94a3b8', fontSize: 9, formatter: '{value}%' },
        },
    ],
    series: [
        {
            name: 'Importe',
            type: 'bar',
            barMaxWidth: 14,
            itemStyle: { borderRadius: [4, 4, 0, 0] },
            data: props.pareto.items.slice(0, 14).map((i) => i.amount),
        },
        {
            name: '% acumulado',
            type: 'line',
            yAxisIndex: 1,
            smooth: true,
            showSymbol: false,
            data: props.pareto.items.slice(0, 14).map((i) => +(i.cumulative_share * 100).toFixed(1)),
        },
    ],
}));

const scatterChartOption = computed(() => ({
    color: [brand],
    tooltip: {
        formatter: (p: any) =>
            `${p.data[3]}<br/>Ventas: ${p.data[0]}<br/>Tasa: ${p.data[1]}%<br/>Importe: ${p.data[2]}`,
        backgroundColor: 'rgba(255,255,255,0.96)',
        borderColor: '#e2e8f0',
        textStyle: { fontSize: 11, color: '#0f172a' },
    },
    grid: { left: 8, right: 12, top: 16, bottom: 8, containLabel: true },
    xAxis: {
        name: 'Unidades vendidas',
        nameTextStyle: { fontSize: 9, color: '#94a3b8' },
        type: 'value',
        splitLine: { lineStyle: { color: '#f1f5f9' } },
        axisLabel: { color: '#94a3b8', fontSize: 9 },
    },
    yAxis: {
        name: 'Tasa %',
        nameTextStyle: { fontSize: 9, color: '#94a3b8' },
        type: 'value',
        splitLine: { lineStyle: { color: '#f1f5f9' } },
        axisLabel: { color: '#94a3b8', fontSize: 9 },
    },
    series: [
        {
            type: 'scatter',
            symbolSize: (val: number[]) => Math.max(8, Math.min(36, Math.sqrt(val[2] || 1) / 4)),
            data: props.scatter.map((s) => [s.x, s.y, s.z, s.name]),
        },
    ],
}));
</script>

<template>
    <Head title="Devoluciones" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="mx-auto max-w-7xl space-y-3 px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Devoluciones"
                    description="Detectá productos problemáticos, patrones y pérdida por devoluciones."
                >
                    <template #actions>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Button
                                as="a"
                                size="sm"
                                variant="outline"
                                class="h-7 px-2.5 text-[11px]"
                                :href="route('returns.items.index', { period: filters.period || period.preset })"
                            >
                                <ClipboardList class="mr-1 size-3.5" />
                                Casos
                            </Button>
                            <Button
                                as="a"
                                size="sm"
                                variant="outline"
                                class="h-7 px-2.5 text-[11px]"
                                :href="route('claims.index')"
                            >
                                Reclamos
                            </Button>
                            <Button
                                as="a"
                                size="sm"
                                class="h-7 px-2.5 text-[11px]"
                                :href="route('dashboard')"
                            >
                                Dashboard
                            </Button>
                        </div>
                    </template>
                </PageHeader>

                <!-- Connection chips -->
                <div
                    v-if="connections.length > 1"
                    class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200/70 bg-white px-3 py-2 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                >
                    <span class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                        Conexiones
                    </span>
                    <button
                        v-for="c in connections"
                        :key="c.id"
                        type="button"
                        class="inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-[11px] font-medium transition-colors"
                        :style="isConnectionSelected(c.id) ? connectionSurfaceStyle(c.color) : undefined"
                        :class="
                            isConnectionSelected(c.id)
                                ? 'connection-chip border-[color:var(--conn-border)] bg-[color:var(--conn-chip)] text-[color:var(--conn-fg)]'
                                : 'border-slate-200 bg-slate-50 text-slate-500 hover:border-slate-300'
                        "
                        @click="toggleConnection(c.id)"
                    >
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: resolveConnectionColor(c.color) }"
                            aria-hidden="true"
                        />
                        {{ connectionFilterLabel(c) }}
                    </button>
                    <span
                        v-if="isFilteringConnections"
                        class="text-[10px] text-muted-foreground"
                    >
                        Filtrando {{ selectedConnectionIds.length }} de {{ connections.length }}
                    </span>
                </div>

                <ReturnsSearchAndFilters
                    :filters="{ ...filters, period: filters.period || period.preset }"
                    :connections="connections"
                    :route-url="route('returns.index')"
                    :before-apply="preserveListContext"
                />

                <div
                    v-if="empty_hint"
                    class="rounded-xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-5 text-sm text-slate-600"
                >
                    <div class="flex items-start gap-2">
                        <RotateCcw class="mt-0.5 size-4 shrink-0 text-slate-400" />
                        <p>{{ empty_hint }}</p>
                    </div>
                </div>

                <!-- KPI strip -->
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Devoluciones · {{ period.label }}
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ kpis.total_returns }}
                        </p>
                        <p
                            class="mt-0.5 text-[10px]"
                            :class="deltaClass(kpis.total_returns_delta_pct)"
                        >
                            {{ formatDelta(kpis.total_returns_delta_pct) }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            {{ kpis.returned_units }} unidades · {{ kpis.products_affected }} productos
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
                            {{ ratePct(kpis.return_rate || 0) }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-muted-foreground">
                            Periodo ant.: {{ ratePct(kpis.previous_return_rate || 0) }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            Prom. histórico: {{ ratePct(kpis.historical_avg_rate || 0) }}
                        </p>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Importe / pérdida est.
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText :amount="kpis.returned_amount" />
                        </p>
                        <p
                            class="mt-0.5 text-[10px]"
                            :class="deltaClass(kpis.returned_amount_delta_pct)"
                        >
                            {{ formatDelta(kpis.returned_amount_delta_pct) }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            Pérdida est.
                            <MoneyText :amount="kpis.estimated_loss" />
                        </p>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Productos en alerta
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ kpis.products_in_alert }}
                        </p>
                        <p
                            v-if="kpis.top_rate_product"
                            class="mt-1 line-clamp-2 text-[10px] text-muted-foreground"
                        >
                            Mayor tasa: {{ kpis.top_rate_product.name }}
                            ({{ ratePct(kpis.top_rate_product.rate) }})
                        </p>
                        <p
                            v-if="kpis.top_loss_product"
                            class="mt-0.5 line-clamp-1 text-[10px] text-muted-foreground"
                        >
                            Mayor $:
                            {{ kpis.top_loss_product.name }}
                        </p>
                    </Card>
                </div>

                <!-- Insights -->
                <section
                    v-if="insights.length"
                    class="space-y-2"
                >
                    <h2 class="text-[13px] font-semibold tracking-tight text-slate-900">
                        Insights
                    </h2>
                    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        <Card
                            v-for="insight in insights"
                            :key="insight.id"
                            class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] border-l-4"
                            :class="insight.severity === 'warning' ? 'border-l-amber-500' : 'border-l-slate-300'"
                            content-class="p-3"
                        >
                            <p class="text-[13px] font-medium text-slate-900">{{ insight.title }}</p>
                            <p class="mt-1 text-[11px] text-muted-foreground">{{ insight.body }}</p>
                        </Card>
                    </div>
                </section>

                <!-- Attention -->
                <section
                    v-if="attention_products.length"
                    class="space-y-2"
                >
                    <div class="flex items-end justify-between gap-2">
                        <h2 class="text-[13px] font-semibold tracking-tight text-slate-900">
                            Productos que requieren atención
                        </h2>
                        <p class="text-[10px] text-muted-foreground">Ordenados por Risk Score</p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <Card
                            v-for="row in attention_products"
                            :key="`att-${row.product_id}-${row.ml_item_id}`"
                            class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                            content-class="space-y-2 p-3"
                        >
                            <div class="flex items-start gap-2">
                                <img
                                    v-if="row.image"
                                    :src="row.image"
                                    alt=""
                                    class="size-10 shrink-0 rounded-md object-cover"
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-medium text-slate-900">
                                        {{ row.name }}
                                    </p>
                                    <p class="truncate text-[10px] text-muted-foreground">
                                        {{ row.sku || row.ml_item_id || '—' }}
                                    </p>
                                    <ConnectionChip
                                        v-if="row.connection"
                                        class="mt-1"
                                        :connection="row.connection"
                                    />
                                </div>
                                <ReturnRiskBadge
                                    :level="row.risk_level"
                                    :score="row.risk_score"
                                />
                            </div>
                            <div class="flex items-end justify-between">
                                <p class="text-lg font-semibold tracking-tight">
                                    {{ ratePct(row.return_rate) }}
                                </p>
                                <ReturnSparkline :values="row.sparkline" />
                            </div>
                            <p
                                v-if="row.narrative"
                                class="line-clamp-2 text-[10px] text-muted-foreground"
                            >
                                {{ row.narrative }}
                            </p>
                            <button
                                type="button"
                                class="inline-block text-[11px] font-semibold text-brand hover:underline"
                                @click="openProduct(row)"
                            >
                                Ver análisis →
                            </button>
                        </Card>
                    </div>
                </section>

                <!-- Tabs -->
                <div class="flex flex-wrap gap-1 border-b border-slate-200 pb-2">
                    <button
                        v-for="t in [
                            { id: 'products', label: 'Productos' },
                            { id: 'reasons', label: 'Motivos' },
                            { id: 'viz', label: 'Visualizaciones' },
                            { id: 'ops', label: 'Logística / Geo' },
                        ]"
                        :key="t.id"
                        type="button"
                        class="rounded-md px-2.5 py-1.5 text-[11px] font-medium transition-colors"
                        :class="
                            activeTab === t.id
                                ? 'bg-slate-900 text-white'
                                : 'text-slate-600 hover:bg-slate-100'
                        "
                        @click="setTab(t.id)"
                    >
                        {{ t.label }}
                    </button>
                </div>

                <!-- Products table -->
                <div
                    v-if="activeTab === 'products'"
                    class="space-y-2"
                >
                    <div class="hidden overflow-hidden rounded-xl border border-slate-200/70 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] md:block">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50/80 text-left text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2.5">Producto</th>
                                    <th class="px-3 py-2.5">Conexión</th>
                                    <th class="px-3 py-2.5">Ventas</th>
                                    <th class="px-3 py-2.5">Dev.</th>
                                    <th class="px-3 py-2.5">Tasa</th>
                                    <th class="px-3 py-2.5">$ Devuelto</th>
                                    <th class="px-3 py-2.5">Motivo</th>
                                    <th class="px-3 py-2.5">Tendencia</th>
                                    <th class="px-3 py-2.5">Riesgo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in products"
                                    :key="`${row.product_id}-${row.ml_item_id}`"
                                    class="border-t border-slate-100 hover:bg-slate-50/70"
                                >
                                    <td class="px-3 py-2.5">
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2.5 text-left"
                                            @click="openProduct(row)"
                                        >
                                            <img
                                                v-if="row.image"
                                                :src="row.image"
                                                alt=""
                                                class="size-9 rounded-md object-cover"
                                            >
                                            <div class="min-w-0">
                                                <p class="truncate font-medium text-slate-900 hover:text-brand">
                                                    {{ row.name }}
                                                </p>
                                                <p class="truncate text-[10px] text-muted-foreground">
                                                    {{ row.sku || '—' }} · {{ row.ml_item_id || '—' }}
                                                </p>
                                                <Badge
                                                    v-if="row.insufficient_sample"
                                                    variant="muted"
                                                    class="mt-1"
                                                >
                                                    Muestra insuficiente
                                                </Badge>
                                            </div>
                                        </button>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <ConnectionChip
                                            v-if="row.connection"
                                            :connection="row.connection"
                                        />
                                        <span
                                            v-else
                                            class="text-xs text-muted-foreground"
                                        >—</span>
                                    </td>
                                    <td class="px-3 py-2.5 tabular-nums">{{ row.units_sold }}</td>
                                    <td class="px-3 py-2.5 tabular-nums">{{ row.returned_units }}</td>
                                    <td class="px-3 py-2.5 font-medium tabular-nums">
                                        {{ ratePct(row.return_rate) }}
                                    </td>
                                    <td class="px-3 py-2.5 tabular-nums">
                                        <MoneyText :amount="row.returned_amount" />
                                    </td>
                                    <td class="px-3 py-2.5 text-xs">
                                        {{ row.dominant_reason_label || '—' }}
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <ReturnSparkline :values="row.sparkline" />
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <ReturnRiskBadge
                                            :level="row.risk_level"
                                            :score="row.risk_score"
                                        />
                                    </td>
                                </tr>
                                <tr v-if="!products.length">
                                    <td
                                        colspan="9"
                                        class="px-3 py-8 text-center text-sm text-muted-foreground"
                                    >
                                        No hay productos con devoluciones en este periodo.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid gap-2 md:hidden">
                        <Card
                            v-for="row in products"
                            :key="`m-${row.product_id}-${row.ml_item_id}`"
                            class="rounded-xl border-slate-200/70"
                            content-class="space-y-2 p-3"
                        >
                            <div class="flex gap-3">
                                <img
                                    v-if="row.image"
                                    :src="row.image"
                                    alt=""
                                    class="size-14 rounded-md object-cover"
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-slate-900">{{ row.name }}</p>
                                    <p class="text-[11px] text-muted-foreground">
                                        {{ ratePct(row.return_rate) }} ·
                                        {{ row.returned_units }}/{{ row.units_sold }}
                                    </p>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <ReturnRiskBadge
                                            :level="row.risk_level"
                                            :score="row.risk_score"
                                        />
                                        <ConnectionChip
                                            v-if="row.connection"
                                            :connection="row.connection"
                                        />
                                    </div>
                                </div>
                            </div>
                            <Button
                                size="sm"
                                class="h-8 w-full text-[11px]"
                                @click="openProduct(row)"
                            >
                                Ver análisis
                            </Button>
                        </Card>
                    </div>
                </div>

                <!-- Reasons -->
                <div
                    v-else-if="activeTab === 'reasons'"
                    class="grid gap-3 lg:grid-cols-2"
                >
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight">
                            Distribución de motivos
                        </h3>
                        <DashboardChart
                            v-if="reasons.length"
                            :option="reasonChartOption"
                            height="240px"
                        />
                        <p
                            v-else
                            class="py-10 text-center text-sm text-muted-foreground"
                        >
                            Sin motivos en el periodo.
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-[10px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="py-2">Motivo</th>
                                    <th>Cant.</th>
                                    <th>%</th>
                                    <th>Importe</th>
                                    <th>Prod.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in reasons"
                                    :key="r.label"
                                    class="border-t border-slate-100"
                                >
                                    <td class="py-2 font-medium">{{ r.label }}</td>
                                    <td class="tabular-nums">{{ r.count }}</td>
                                    <td class="tabular-nums">{{ (r.share * 100).toFixed(0) }}%</td>
                                    <td class="tabular-nums">
                                        <MoneyText :amount="r.amount" />
                                    </td>
                                    <td class="tabular-nums">{{ r.products_affected }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] lg:col-span-2"
                        content-class="p-3"
                    >
                        <h3 class="mb-2 text-[13px] font-semibold tracking-tight">
                            Categorías
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <div
                                v-for="c in categories"
                                :key="c.category"
                                class="rounded-lg border border-slate-100 bg-slate-50/50 px-3 py-2.5"
                            >
                                <p class="truncate text-[12px] font-medium">{{ c.category }}</p>
                                <p class="mt-0.5 text-lg font-semibold tracking-tight">
                                    {{ ratePct(c.return_rate) }}
                                </p>
                                <p class="text-[10px] text-muted-foreground">
                                    {{ c.products }} productos ·
                                    <MoneyText :amount="c.returned_amount" />
                                </p>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Viz -->
                <div
                    v-else-if="activeTab === 'viz'"
                    class="grid gap-3 lg:grid-cols-2"
                >
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] lg:col-span-2"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight">
                            Pareto de pérdida
                        </h3>
                        <p class="mb-2 text-[10px] text-muted-foreground">
                            El 80% del importe suele concentrarse en pocos productos
                            <span v-if="pareto.cutoff_index">
                                (corte ≈ {{ pareto.cutoff_index }} productos)
                            </span>
                        </p>
                        <DashboardChart
                            :option="paretoChartOption"
                            height="280px"
                        />
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight">
                            Volumen vs tasa
                        </h3>
                        <DashboardChart
                            :option="scatterChartOption"
                            height="260px"
                        />
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-2 text-[13px] font-semibold tracking-tight">
                            Días hasta devolución
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <div
                                v-for="b in days_to_return"
                                :key="b.bucket"
                                class="min-w-[88px] flex-1 rounded-lg border border-slate-100 bg-slate-50/60 px-3 py-2"
                            >
                                <p class="text-[10px] text-muted-foreground">{{ b.bucket }} días</p>
                                <p class="text-lg font-semibold tabular-nums">{{ b.count }}</p>
                                <p class="text-[10px] text-muted-foreground">
                                    {{ (b.share * 100).toFixed(0) }}%
                                </p>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Ops -->
                <div
                    v-else
                    class="grid gap-3 lg:grid-cols-2"
                >
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight">
                            Logística (correlación)
                        </h3>
                        <p class="mb-2 text-[10px] text-muted-foreground">
                            No implica causalidad.
                        </p>
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-[10px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="py-2">Carrier</th>
                                    <th>Tipo</th>
                                    <th>Dev.</th>
                                    <th>% daño</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(l, idx) in logistics"
                                    :key="idx"
                                    class="border-t border-slate-100"
                                >
                                    <td class="py-2">{{ l.carrier }}</td>
                                    <td>{{ l.logistic_type }}</td>
                                    <td class="tabular-nums">{{ l.returns }}</td>
                                    <td class="tabular-nums">
                                        {{ ((l.damaged_rate || 0) * 100).toFixed(0) }}%
                                    </td>
                                </tr>
                                <tr v-if="!logistics.length">
                                    <td
                                        colspan="4"
                                        class="py-6 text-center text-muted-foreground"
                                    >
                                        Sin datos logísticos suficientes.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-2 text-[13px] font-semibold tracking-tight">
                            Región geográfica
                        </h3>
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-[10px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="py-2">Estado</th>
                                    <th>Ciudad</th>
                                    <th>Dev.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(g, idx) in geo"
                                    :key="idx"
                                    class="border-t border-slate-100"
                                >
                                    <td class="py-2">{{ g.state }}</td>
                                    <td>{{ g.city || '—' }}</td>
                                    <td class="tabular-nums">{{ g.returns }}</td>
                                </tr>
                                <tr v-if="!geo.length">
                                    <td
                                        colspan="3"
                                        class="py-6 text-center text-muted-foreground"
                                    >
                                        Sin datos geográficos suficientes.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </Card>
                </div>
            </div>
        </div>

        <ReturnProductSlideOver
            :show="productSlideOpen"
            :product-key="selectedProductKey"
            :period="filters.period || period.preset"
            :connection-ids="isFilteringConnections ? selectedConnectionIds : []"
            @close="closeProduct"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
.connection-chip {
    color: var(--conn-fg);
    background: var(--conn-chip);
    border-color: var(--conn-border);
}
</style>
