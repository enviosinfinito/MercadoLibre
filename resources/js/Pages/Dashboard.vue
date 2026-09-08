<script setup lang="ts">
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import MissingCostAlertBanner from '@/Components/Catalog/MissingCostAlertBanner.vue';
import DashboardChart from '@/Components/Dashboard/DashboardChart.vue';
import StackedData from '@/Components/App/StackedData.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import OrderStatusPill from '@/Components/Domain/OrderStatusPill.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDateTime, formatRelativeShort } from '@/lib/utils';
import { connectionFilterLabel, providerLabel } from '@/lib/connectionLabel';
import {
    connectionSurfaceStyle,
    resolveConnectionColor,
} from '@/lib/connectionColor';
import { toTitleCase } from '@/lib/formatDisplayText';

interface BuyerDisplay {
    primary: string;
    secondary?: string | null;
    primary_kind: string;
    secondary_kind?: string | null;
}

const props = defineProps<{
    kpis: {
        orders_today: number;
        orders_yesterday: number;
        revenue_today: number;
        revenue_30d: number;
        revenue_prev_30d: number;
        orders_30d: number;
        orders_prev_30d: number;
        open_alerts: number;
        failed_outbound_24h: number;
        open_dead_letters: number;
        connections_healthy: number;
        connections_total: number;
        listings_without_cost: number;
        open_claims: number;
        unanswered_questions: number;
        currency: string;
    };
    finance: {
        expected_profit: number;
        revenue: number;
        fees: number;
        cogs: number;
        incomplete_orders: number;
        currency: string;
    };
    orders_series: Array<{
        day: string;
        label: string;
        orders: number;
        revenue: number;
        unsuccessful_orders?: number;
    }>;
    orders_by_status: Record<string, number>;
    sales_quality: {
        total_orders: number;
        successful_orders: number;
        successful_revenue: number;
        cancelled_orders: number;
        cancelled_revenue: number;
        returned_orders: number;
        refunded_orders: number;
        partial_refunded_orders: number;
        post_sale_reversed_orders: number;
        post_sale_reversed_revenue: number;
        claim_open_orders: number;
        cancellation_rate: number;
        return_rate: number;
    };
    top_products: Array<{
        product_id: number | null;
        name: string;
        sku: string | null;
        connection_id: number | null;
        connection_label: string;
        connection_color?: string | null;
        units: number;
        revenue: number;
    }>;
    top_returned_products: Array<{
        product_id: number | null;
        name: string;
        sku: string | null;
        orders_count: number;
        units: number;
        revenue: number;
    }>;
    recent_orders: Array<{
        id: number;
        external_order_id: string | null;
        status: string;
        post_sale_outcome?: string | null;
        currency_code: string;
        total_amount: string | number;
        ordered_at: string | null;
        platform: string | null;
        buyer_display: BuyerDisplay | null;
    }>;
    connections: Array<{
        id: number;
        provider: string;
        display_name?: string | null;
        external_user_id?: string | null;
        color?: string | null;
        status: string;
        freshness_status: string | null;
        last_synced_at: string | null;
        needs_reauthorization: boolean;
    }>;
    filters: {
        connection_ids: number[];
    };
    last_sync: {
        id: number;
        status: string;
        resource_type: string | null;
        started_at: string | null;
        finished_at: string | null;
    } | null;
}>();

const allConnectionIds = computed(() => props.connections.map((c) => c.id));

/** Effective selection: empty filters.connection_ids means all. */
const selectedConnectionIds = computed(() => {
    const fromFilters = props.filters?.connection_ids ?? [];
    if (!fromFilters.length) {
        return allConnectionIds.value;
    }
    return fromFilters;
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

function toggleConnection(id: number): void {
    if (props.connections.length <= 1) {
        return;
    }

    const current = [...selectedConnectionIds.value];
    const idx = current.indexOf(id);
    if (idx >= 0) {
        if (current.length <= 1) {
            return;
        }
        current.splice(idx, 1);
    } else {
        current.push(id);
    }

    current.sort((a, b) => a - b);
    const allSelected =
        current.length === allConnectionIds.value.length &&
        allConnectionIds.value.every((cid) => current.includes(cid));

    router.get(
        route('dashboard'),
        allSelected ? {} : { connection_ids: current },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function pctDelta(current: number, previous: number): number | null {
    if (previous === 0) {
        return current === 0 ? 0 : null;
    }
    return ((current - previous) / previous) * 100;
}

function formatDelta(delta: number | null): string {
    if (delta == null) return 'vs periodo ant.';
    const sign = delta > 0 ? '+' : '';
    return `${sign}${delta.toFixed(1)}% vs 30d ant.`;
}

const revenueDelta = computed(() =>
    pctDelta(props.kpis.revenue_30d, props.kpis.revenue_prev_30d),
);
const ordersDelta = computed(() =>
    pctDelta(props.kpis.orders_30d, props.kpis.orders_prev_30d),
);
const ordersTodayDelta = computed(() =>
    pctDelta(props.kpis.orders_today, props.kpis.orders_yesterday),
);

const brand = '#0f766e';

const salesChartOption = computed(() => {
    const labels = props.orders_series.map((p) => p.label);
    const revenue = props.orders_series.map((p) => p.revenue);
    const orders = props.orders_series.map((p) => p.orders);
    const unsuccessful = props.orders_series.map((p) => p.unsuccessful_orders ?? 0);

    return {
        color: [brand, '#94a3b8', '#f87171'],
        tooltip: {
            trigger: 'axis',
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: '#e2e8f0',
            textStyle: { color: '#0f172a', fontSize: 11 },
        },
        legend: {
            top: 0,
            right: 0,
            textStyle: { fontSize: 10, color: '#64748b' },
            itemWidth: 10,
            itemHeight: 8,
        },
        grid: { left: 8, right: 8, top: 28, bottom: 4, containLabel: true },
        xAxis: {
            type: 'category',
            data: labels,
            axisLine: { lineStyle: { color: '#e2e8f0' } },
            axisTick: { show: false },
            axisLabel: {
                color: '#94a3b8',
                fontSize: 9,
                interval: 4,
            },
        },
        yAxis: [
            {
                type: 'value',
                name: 'Ingresos',
                nameTextStyle: { fontSize: 9, color: '#94a3b8' },
                splitLine: { lineStyle: { color: '#f1f5f9' } },
                axisLabel: {
                    color: '#94a3b8',
                    fontSize: 9,
                    formatter: (v: number) =>
                        v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v),
                },
            },
            {
                type: 'value',
                name: 'Órdenes',
                nameTextStyle: { fontSize: 9, color: '#94a3b8' },
                splitLine: { show: false },
                axisLabel: { color: '#94a3b8', fontSize: 9 },
            },
        ],
        series: [
            {
                name: 'Ingresos netos',
                type: 'line',
                smooth: true,
                showSymbol: false,
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0,
                        y: 0,
                        x2: 0,
                        y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(15,118,110,0.28)' },
                            { offset: 1, color: 'rgba(15,118,110,0.02)' },
                        ],
                    },
                },
                lineStyle: { width: 2 },
                data: revenue,
            },
            {
                name: 'Exitosas',
                type: 'bar',
                yAxisIndex: 1,
                stack: 'orders',
                barMaxWidth: 8,
                itemStyle: { borderRadius: [0, 0, 0, 0], color: '#cbd5e1' },
                data: orders,
            },
            {
                name: 'Cancel./dev.',
                type: 'bar',
                yAxisIndex: 1,
                stack: 'orders',
                barMaxWidth: 8,
                itemStyle: { borderRadius: [3, 3, 0, 0], color: '#fca5a5' },
                data: unsuccessful,
            },
        ],
    };
});

const statusChartOption = computed(() => {
    const entries = Object.entries(props.orders_by_status || {});
    const data = entries.map(([name, value]) => ({ name, value }));

    return {
        color: ['#0f766e', '#14b8a6', '#38bdf8', '#f59e0b', '#ef4444', '#94a3b8', '#a78bfa'],
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
                avoidLabelOverlap: true,
                itemStyle: { borderRadius: 4, borderColor: '#fff', borderWidth: 2 },
                label: { show: false },
                data,
            },
        ],
    };
});

const connectionPalette = [
    '#0f766e',
    '#0284c7',
    '#d97706',
    '#7c3aed',
    '#dc2626',
    '#059669',
    '#ea580c',
    '#4f46e5',
];

const topProductsChartOption = computed(() => {
    const rows = props.top_products ?? [];

    const productOrder: string[] = [];
    const productMeta = new Map<string, { name: string; sku: string | null }>();
    const productTotals = new Map<string, number>();

    for (const row of rows) {
        const key =
            row.product_id != null
                ? `p:${row.product_id}`
                : `n:${row.name.toLowerCase()}`;
        if (!productMeta.has(key)) {
            productMeta.set(key, { name: row.name, sku: row.sku });
            productOrder.push(key);
        }
        productTotals.set(key, (productTotals.get(key) ?? 0) + Number(row.revenue || 0));
    }

    productOrder.sort(
        (a, b) => (productTotals.get(a) ?? 0) - (productTotals.get(b) ?? 0),
    );

    const connectionOrder: string[] = [];
    const connectionLabels = new Map<string, string>();
    const connectionColors = new Map<string, string>();
    for (const row of rows) {
        const cKey = row.connection_id != null ? String(row.connection_id) : 'none';
        if (!connectionLabels.has(cKey)) {
            connectionLabels.set(cKey, row.connection_label || 'Sin conexión');
            const fromProp = props.connections.find(
                (c) => String(c.id) === cKey,
            )?.color;
            connectionColors.set(
                cKey,
                resolveConnectionColor(
                    row.connection_color ?? fromProp,
                    connectionOrder.length,
                ),
            );
            connectionOrder.push(cKey);
        }
    }

    const seriesColors = connectionOrder.map(
        (cKey, index) =>
            connectionColors.get(cKey) ??
            connectionPalette[index % connectionPalette.length],
    );

    const categories = productOrder.map((key) => {
        const name = toTitleCase(productMeta.get(key)?.name ?? 'Producto');
        return name.length > 28 ? `${name.slice(0, 26)}…` : name;
    });

    const series = connectionOrder.map((cKey, index) => {
        const data = productOrder.map((pKey) => {
            const match = rows.find((row) => {
                const rowKey =
                    row.product_id != null
                        ? `p:${row.product_id}`
                        : `n:${row.name.toLowerCase()}`;
                const rowConn =
                    row.connection_id != null ? String(row.connection_id) : 'none';
                return rowKey === pKey && rowConn === cKey;
            });
            return match
                ? {
                      value: match.revenue,
                      sku: match.sku,
                      productName: toTitleCase(match.name),
                      connectionLabel: match.connection_label,
                  }
                : { value: 0, sku: null, productName: '', connectionLabel: '' };
        });

        return {
            name: connectionLabels.get(cKey) ?? 'Sin conexión',
            type: 'bar',
            stack: 'revenue',
            barMaxWidth: 16,
            itemStyle: {
                color: seriesColors[index],
                borderRadius:
                    index === connectionOrder.length - 1 ? [0, 6, 6, 0] : 0,
            },
            data,
        };
    });

    return {
        color: seriesColors,
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: '#e2e8f0',
            textStyle: { fontSize: 11, color: '#0f172a' },
            formatter: (params: Array<{ seriesName: string; data: { value: number; sku?: string | null; productName?: string; connectionLabel?: string }; marker: string }>) => {
                const active = params.filter((p) => Number(p.data?.value || 0) > 0);
                if (!active.length) return '';
                const title = active[0]?.data?.productName || '';
                const sku = active.find((p) => p.data?.sku)?.data?.sku;
                const lines = [
                    `<div style="font-weight:600;margin-bottom:4px">${title}</div>`,
                    sku ? `<div style="color:#64748b;margin-bottom:6px">SKU ${sku}</div>` : '',
                    ...active.map(
                        (p) =>
                            `${p.marker} ${p.seriesName}: <b>${Number(p.data.value).toLocaleString('es-MX', { style: 'currency', currency: props.kpis.currency || 'MXN' })}</b>`,
                    ),
                ];
                return lines.filter(Boolean).join('<br/>');
            },
        },
        legend: {
            top: 0,
            type: 'scroll',
            textStyle: { fontSize: 10, color: '#64748b' },
            itemWidth: 10,
            itemHeight: 8,
        },
        grid: { left: 8, right: 16, top: 28, bottom: 4, containLabel: true },
        xAxis: {
            type: 'value',
            axisLabel: {
                color: '#94a3b8',
                fontSize: 9,
                formatter: (v: number) =>
                    v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v),
            },
            splitLine: { lineStyle: { color: '#f1f5f9' } },
        },
        yAxis: {
            type: 'category',
            data: categories,
            axisTick: { show: false },
            axisLine: { show: false },
            axisLabel: { color: '#64748b', fontSize: 10 },
        },
        series,
    };
});

const pnlChartOption = computed(() => {
    const f = props.finance;
    return {
        color: [brand, '#f59e0b', '#64748b', '#0ea5e9'],
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: '#e2e8f0',
            textStyle: { fontSize: 11, color: '#0f172a' },
        },
        grid: { left: 8, right: 8, top: 12, bottom: 4, containLabel: true },
        xAxis: {
            type: 'category',
            data: ['Ingresos', 'Fees', 'COGS', 'Utilidad'],
            axisTick: { show: false },
            axisLine: { lineStyle: { color: '#e2e8f0' } },
            axisLabel: { color: '#64748b', fontSize: 10 },
        },
        yAxis: {
            type: 'value',
            splitLine: { lineStyle: { color: '#f1f5f9' } },
            axisLabel: {
                color: '#94a3b8',
                fontSize: 9,
                formatter: (v: number) =>
                    v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v),
            },
        },
        series: [
            {
                type: 'bar',
                barMaxWidth: 28,
                data: [
                    {
                        value: f.revenue,
                        itemStyle: { color: brand, borderRadius: [6, 6, 0, 0] },
                    },
                    {
                        value: f.fees,
                        itemStyle: { color: '#f59e0b', borderRadius: [6, 6, 0, 0] },
                    },
                    {
                        value: f.cogs,
                        itemStyle: { color: '#94a3b8', borderRadius: [6, 6, 0, 0] },
                    },
                    {
                        value: f.expected_profit,
                        itemStyle: {
                            color: f.expected_profit >= 0 ? '#0ea5e9' : '#ef4444',
                            borderRadius: [6, 6, 0, 0],
                        },
                    },
                ],
            },
        ],
    };
});

function deltaClass(delta: number | null) {
    if (delta == null) return 'text-muted-foreground';
    if (delta > 0) return 'text-emerald-700';
    if (delta < 0) return 'text-red-600';
    return 'text-muted-foreground';
}

function connectionAccount(c: (typeof props.connections)[0]) {
    return c.display_name?.trim() || c.external_user_id || '—';
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="w-full space-y-4 px-4 sm:px-6 lg:px-8">
                <PageHeader
                    compact
                    title="Dashboard"
                    description="Pulse operativo, ventas y rentabilidad del workspace."
                >
                    <template #actions>
                        <div class="flex flex-wrap gap-1.5">
                            <Button
                                as="a"
                                size="sm"
                                variant="outline"
                                class="h-7 px-2.5 text-[11px]"
                                :href="route('orders.index')"
                            >
                                Órdenes
                            </Button>
                            <Button
                                as="a"
                                size="sm"
                                variant="outline"
                                class="h-7 px-2.5 text-[11px]"
                                :href="route('finance.dashboard')"
                            >
                                Finanzas
                            </Button>
                            <Button
                                as="a"
                                size="sm"
                                class="h-7 px-2.5 text-[11px]"
                                :href="route('monitoring.index')"
                            >
                                Monitoreo
                            </Button>
                        </div>
                    </template>
                </PageHeader>

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
                        :style="
                            isConnectionSelected(c.id)
                                ? connectionSurfaceStyle(c.color)
                                : undefined
                        "
                        :class="
                            isConnectionSelected(c.id)
                                ? 'connection-chip border-[color:var(--conn-border)] bg-[color:var(--conn-chip)] text-[color:var(--conn-fg)]'
                                : 'border-slate-200 bg-slate-50 text-slate-500 hover:border-slate-300'
                        "
                        @click="toggleConnection(c.id)"
                    >
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :style="{
                                backgroundColor: resolveConnectionColor(c.color),
                            }"
                            aria-hidden="true"
                        />
                        {{ connectionFilterLabel(c) }}
                    </button>
                    <span
                        v-if="isFilteringConnections"
                        class="text-[10px] text-muted-foreground"
                    >
                        Filtrando {{ selectedConnectionIds.length }} de
                        {{ connections.length }} conexiones
                    </span>
                </div>

                <MissingCostAlertBanner
                    :count="kpis.listings_without_cost"
                    class="mb-0 rounded-xl px-3 py-2 text-xs"
                />

                <!-- KPI strip -->
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Órdenes hoy · exitosas
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ kpis.orders_today }}
                        </p>
                        <p class="mt-0.5 text-[10px]" :class="deltaClass(ordersTodayDelta)">
                            {{ formatDelta(ordersTodayDelta).replace('30d ant.', 'ayer') }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            {{ sales_quality.cancelled_orders }} cancel. ·
                            {{ sales_quality.post_sale_reversed_orders }} dev./reemb. (30d)
                        </p>
                        <Link
                            :href="route('orders.index')"
                            class="mt-2 inline-block text-[11px] font-semibold text-brand hover:underline"
                        >
                            Ver inbox →
                        </Link>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Ingresos 30d · netos
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText
                                :amount="kpis.revenue_30d"
                                :currency="kpis.currency"
                            />
                        </p>
                        <p class="mt-0.5 text-[10px]" :class="deltaClass(revenueDelta)">
                            {{ formatDelta(revenueDelta) }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            Hoy:
                            <MoneyText
                                :amount="kpis.revenue_today"
                                :currency="kpis.currency"
                            />
                        </p>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Utilidad esperada
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText
                                :amount="finance.expected_profit"
                                :currency="finance.currency"
                            />
                        </p>
                        <p class="mt-0.5 text-[10px] text-muted-foreground">
                            {{ finance.incomplete_orders }} órdenes incompletas
                        </p>
                        <Link
                            :href="route('finance.dashboard')"
                            class="mt-2 inline-block text-[11px] font-semibold text-brand hover:underline"
                        >
                            Ver P&amp;L →
                        </Link>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Salud operativa
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ kpis.connections_healthy }}/{{ kpis.connections_total }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-muted-foreground">
                            {{ kpis.open_alerts }} alertas · {{ kpis.failed_outbound_24h }} fallos 24h
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            {{ kpis.open_claims }} reclamos · {{ kpis.unanswered_questions }} preguntas
                        </p>
                    </Card>
                </div>

                <!-- Charts row -->
                <div class="grid gap-3 xl:grid-cols-3">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] xl:col-span-2"
                        content-class="p-3"
                    >
                        <div class="mb-1 flex items-end justify-between gap-2">
                            <div>
                                <h3 class="text-[13px] font-semibold tracking-tight text-slate-900">
                                    Ventas netas · 30 días
                                </h3>
                                <p class="text-[10px] text-muted-foreground">
                                    {{ kpis.orders_30d }} exitosas ·
                                    <span :class="deltaClass(ordersDelta)">{{ formatDelta(ordersDelta) }}</span>
                                </p>
                            </div>
                        </div>
                        <DashboardChart :option="salesChartOption" height="240px" />
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight text-slate-900">
                            Órdenes por estado
                        </h3>
                        <p class="mb-1 text-[10px] text-muted-foreground">Últimos 30 días</p>
                        <DashboardChart
                            v-if="Object.keys(orders_by_status).length"
                            :option="statusChartOption"
                            height="220px"
                        />
                        <p v-else class="py-10 text-center text-xs text-muted-foreground">
                            Sin órdenes en el periodo.
                        </p>
                    </Card>
                </div>

                <!-- Sales quality -->
                <div class="grid gap-3 xl:grid-cols-3">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <div class="mb-2 flex items-start justify-between gap-2">
                            <div>
                                <h3 class="text-[13px] font-semibold tracking-tight text-slate-900">
                                    Calidad de venta · 30d
                                </h3>
                                <p class="text-[10px] text-muted-foreground">
                                    {{ sales_quality.total_orders }} órdenes totales
                                </p>
                            </div>
                            <Link
                                :href="route('orders.index', { tab: 'cancelled' })"
                                class="text-[11px] font-semibold text-brand hover:underline"
                            >
                                Ver canceladas
                            </Link>
                        </div>
                        <dl class="space-y-2">
                            <div class="flex items-baseline justify-between gap-2">
                                <dt class="text-[11px] text-slate-600">Exitosas</dt>
                                <dd class="text-right">
                                    <span class="text-[13px] font-semibold tabular-nums text-slate-900">
                                        {{ sales_quality.successful_orders }}
                                    </span>
                                    <span class="ml-1 text-[10px] text-muted-foreground">
                                        <MoneyText
                                            :amount="sales_quality.successful_revenue"
                                            :currency="kpis.currency"
                                        />
                                    </span>
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-2">
                                <dt class="text-[11px] text-slate-600">
                                    Canceladas
                                    <span class="text-muted-foreground">({{ sales_quality.cancellation_rate }}%)</span>
                                </dt>
                                <dd class="text-right">
                                    <span class="text-[13px] font-semibold tabular-nums text-rose-700">
                                        {{ sales_quality.cancelled_orders }}
                                    </span>
                                    <span class="ml-1 text-[10px] text-muted-foreground">
                                        <MoneyText
                                            :amount="sales_quality.cancelled_revenue"
                                            :currency="kpis.currency"
                                        />
                                    </span>
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-2">
                                <dt class="text-[11px] text-slate-600">
                                    Dev. / reemb.
                                    <span class="text-muted-foreground">({{ sales_quality.return_rate }}%)</span>
                                </dt>
                                <dd class="text-right">
                                    <span class="text-[13px] font-semibold tabular-nums text-amber-700">
                                        {{ sales_quality.post_sale_reversed_orders }}
                                    </span>
                                    <span class="ml-1 text-[10px] text-muted-foreground">
                                        <MoneyText
                                            :amount="sales_quality.post_sale_reversed_revenue"
                                            :currency="kpis.currency"
                                        />
                                    </span>
                                </dd>
                            </div>
                            <div class="flex flex-wrap gap-1.5 pt-1">
                                <Badge
                                    v-if="sales_quality.returned_orders"
                                    variant="secondary"
                                    class="text-[10px]"
                                >
                                    {{ sales_quality.returned_orders }} devoluciones
                                </Badge>
                                <Badge
                                    v-if="sales_quality.refunded_orders"
                                    variant="secondary"
                                    class="text-[10px]"
                                >
                                    {{ sales_quality.refunded_orders }} reembolsos
                                </Badge>
                                <Badge
                                    v-if="sales_quality.partial_refunded_orders"
                                    variant="secondary"
                                    class="text-[10px]"
                                >
                                    {{ sales_quality.partial_refunded_orders }} parciales
                                </Badge>
                                <Badge
                                    v-if="sales_quality.claim_open_orders"
                                    variant="warning"
                                    class="text-[10px]"
                                >
                                    {{ sales_quality.claim_open_orders }} reclamo abierto
                                </Badge>
                            </div>
                        </dl>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] xl:col-span-2"
                        content-class="p-3"
                    >
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div>
                                <h3 class="text-[13px] font-semibold tracking-tight text-slate-900">
                                    Productos con más devoluciones
                                </h3>
                                <p class="text-[10px] text-muted-foreground">
                                    Órdenes con devolución o reembolso · 30d
                                </p>
                            </div>
                        </div>
                        <div v-if="!top_returned_products.length" class="py-8 text-center text-xs text-muted-foreground">
                            Sin devoluciones ni reembolsos en el periodo.
                        </div>
                        <ul v-else class="divide-y divide-slate-100">
                            <li
                                v-for="(row, idx) in top_returned_products"
                                :key="`${row.product_id ?? row.name}-${idx}`"
                                class="flex items-center gap-3 py-2"
                            >
                                <span class="w-5 shrink-0 text-[10px] font-medium text-muted-foreground">
                                    {{ idx + 1 }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[12px] font-medium text-slate-900">
                                        {{ toTitleCase(row.name) }}
                                    </p>
                                    <p v-if="row.sku" class="text-[10px] text-muted-foreground">
                                        SKU {{ row.sku }}
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-[12px] font-semibold tabular-nums text-amber-800">
                                        {{ row.orders_count }} órdenes
                                    </p>
                                    <p class="text-[10px] text-muted-foreground">
                                        {{ row.units }} u ·
                                        <MoneyText :amount="row.revenue" :currency="kpis.currency" />
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </Card>
                </div>

                <div class="grid gap-3 xl:grid-cols-3">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight text-slate-900">
                            P&amp;L esperado
                        </h3>
                        <p class="mb-1 text-[10px] text-muted-foreground">
                            Snapshots de utilidad (expected)
                        </p>
                        <DashboardChart :option="pnlChartOption" height="200px" />
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] xl:col-span-2"
                        content-class="p-3"
                    >
                        <h3 class="mb-1 text-[13px] font-semibold tracking-tight text-slate-900">
                            Top productos · ingresos 30d
                        </h3>
                        <DashboardChart
                            v-if="top_products.length"
                            :option="topProductsChartOption"
                            height="220px"
                        />
                        <p v-else class="py-10 text-center text-xs text-muted-foreground">
                            Aún no hay líneas de venta en el periodo.
                        </p>
                    </Card>
                </div>

                <!-- Tables / ops -->
                <div class="grid gap-3 lg:grid-cols-5">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] lg:col-span-3"
                        content-class="p-0"
                    >
                        <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2">
                            <h3 class="text-[13px] font-semibold tracking-tight text-slate-900">
                                Órdenes recientes
                            </h3>
                            <Link
                                :href="route('orders.index')"
                                class="text-[11px] font-semibold text-brand hover:underline"
                            >
                                Ver todas
                            </Link>
                        </div>
                        <div v-if="recent_orders.length === 0" class="px-3 py-8 text-center text-xs text-muted-foreground">
                            Sin órdenes todavía.
                        </div>
                        <ul v-else class="divide-y divide-slate-100">
                            <li
                                v-for="order in recent_orders"
                                :key="order.id"
                                class="flex items-center gap-3 px-3 py-2"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="text-[12px] font-medium tracking-tight text-slate-900">
                                            #{{ order.external_order_id ?? order.id }}
                                        </span>
                                        <OrderStatusPill
                                            compact
                                            :status="order.status"
                                            :outcome="order.post_sale_outcome"
                                        />
                                        <span
                                            v-if="order.platform"
                                            class="inline-flex h-5 items-center rounded-full bg-slate-100 px-1.5 text-[10px] font-medium text-slate-600"
                                        >
                                            {{ order.platform }}
                                        </span>
                                    </div>
                                    <StackedData
                                        v-if="order.buyer_display"
                                        class="mt-0.5"
                                        :primary="order.buyer_display.primary"
                                        :secondary="order.buyer_display.secondary"
                                        :primary-kind="order.buyer_display.primary_kind"
                                        :secondary-kind="order.buyer_display.secondary_kind"
                                    />
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-[12px] font-semibold tabular-nums text-slate-900">
                                        <MoneyText
                                            :amount="order.total_amount"
                                            :currency="order.currency_code"
                                        />
                                    </p>
                                    <p
                                        v-if="order.ordered_at"
                                        class="text-[10px] text-muted-foreground"
                                        :title="formatDateTime(order.ordered_at)"
                                    >
                                        {{ formatRelativeShort(order.ordered_at) }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </Card>

                    <div class="space-y-3 lg:col-span-2">
                        <Card
                            class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                            content-class="p-3"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-[13px] font-semibold tracking-tight text-slate-900">
                                    Conexiones
                                </h3>
                                <Link
                                    :href="route('connections.index')"
                                    class="text-[11px] font-semibold text-brand hover:underline"
                                >
                                    Gestionar
                                </Link>
                            </div>
                            <div
                                v-if="connections.length === 0"
                                class="text-xs text-muted-foreground"
                            >
                                Sin conexiones. Vincula Mercado Libre para empezar.
                            </div>
                            <ul v-else class="divide-y divide-slate-100">
                                <li
                                    v-for="c in connections"
                                    :key="c.id"
                                    class="flex items-center justify-between gap-2 py-1.5"
                                >
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span
                                            class="size-2.5 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: resolveConnectionColor(c.color),
                                            }"
                                            aria-hidden="true"
                                        />
                                        <div class="min-w-0">
                                            <p class="truncate text-[12px] font-medium text-slate-900">
                                                {{ providerLabel(c.provider) }}
                                            </p>
                                            <p class="truncate text-[10px] text-muted-foreground">
                                                {{ connectionAccount(c) }}
                                            </p>
                                        </div>
                                    </div>
                                    <Badge
                                        :variant="
                                            c.needs_reauthorization || c.status !== 'active'
                                                ? 'danger'
                                                : c.freshness_status === 'stale'
                                                  ? 'warning'
                                                  : 'success'
                                        "
                                        class="h-5 shrink-0 rounded-full px-1.5 py-0 text-[10px] font-medium"
                                    >
                                        {{
                                            c.needs_reauthorization
                                                ? 'Reautorizar'
                                                : c.freshness_status || c.status
                                        }}
                                    </Badge>
                                </li>
                            </ul>
                        </Card>

                        <Card
                            class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                            content-class="p-3"
                        >
                            <h3 class="mb-2 text-[13px] font-semibold tracking-tight text-slate-900">
                                Último sync
                            </h3>
                            <template v-if="last_sync">
                                <dl class="grid grid-cols-2 gap-2 text-[12px]">
                                    <div>
                                        <dt class="text-[10px] text-muted-foreground">Estado</dt>
                                        <dd class="mt-0.5 capitalize">{{ last_sync.status }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] text-muted-foreground">Recurso</dt>
                                        <dd class="mt-0.5">{{ last_sync.resource_type ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] text-muted-foreground">Inicio</dt>
                                        <dd class="mt-0.5 text-[11px]">
                                            {{
                                                last_sync.started_at
                                                    ? formatRelativeShort(last_sync.started_at)
                                                    : '—'
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] text-muted-foreground">Fin</dt>
                                        <dd class="mt-0.5 text-[11px]">
                                            {{
                                                last_sync.finished_at
                                                    ? formatRelativeShort(last_sync.finished_at)
                                                    : '—'
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </template>
                            <p v-else class="text-xs text-muted-foreground">
                                Todavía no hay corridas de sync.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <Button
                                    as="a"
                                    size="sm"
                                    variant="outline"
                                    class="h-7 px-2 text-[11px]"
                                    :href="route('stock.index')"
                                >
                                    Stock
                                </Button>
                                <Button
                                    as="a"
                                    size="sm"
                                    variant="outline"
                                    class="h-7 px-2 text-[11px]"
                                    :href="route('claims.index')"
                                >
                                    Reclamos
                                </Button>
                                <Button
                                    as="a"
                                    size="sm"
                                    variant="outline"
                                    class="h-7 px-2 text-[11px]"
                                    :href="route('questions.index')"
                                >
                                    Preguntas
                                </Button>
                            </div>
                        </Card>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
