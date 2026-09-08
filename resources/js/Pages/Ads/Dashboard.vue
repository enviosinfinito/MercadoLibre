<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import DashboardChart from '@/Components/Dashboard/DashboardChart.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { connectionFilterLabel } from '@/lib/connectionLabel';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import { Megaphone } from 'lucide-vue-next';

use([CanvasRenderer, BarChart, LineChart, GridComponent, TooltipComponent, LegendComponent]);

interface ConnectionRow {
    id: number;
    provider: string;
    external_user_id?: string | null;
    display_name?: string | null;
    color?: string | null;
}

const props = defineProps<{
    metrics: {
        period: { key: string; label: string; start: string; end: string };
        kpis: Record<string, any>;
        series: Array<{ bucket: string; label: string; cost: number; attributed_revenue: number; clicks: number }>;
        breakdown: Array<Record<string, any>>;
        group_by: string;
        filters: Record<string, any>;
        campaigns: Array<{ id: number; name: string; external_campaign_id: string; status?: string | null }>;
        has_data: boolean;
    };
    syncStatus?: {
        ads_enabled_connections: number;
        connections_total: number;
        permission_blocked?: boolean;
        last_run?: {
            id: number;
            connection_id: number;
            status: string;
            error?: string | null;
            stats?: Record<string, unknown> | null;
            finished_at?: string | null;
        } | null;
    };
    connections: ConnectionRow[];
    groupByOptions: Array<{ value: string; label: string }>;
    periodOptions: Array<{ value: string; label: string }>;
}>();

const kpis = computed(() => props.metrics.kpis || {});
const currency = computed(() => kpis.value.currency || 'MXN');
const filters = computed(() => props.metrics.filters || {});

const allConnectionIds = computed(() => props.connections.map((c) => c.id));
const selectedConnectionIds = computed(() => {
    const fromFilters = filters.value.connection_ids ?? [];
    if (!fromFilters.length) return allConnectionIds.value;
    return fromFilters.map((id: number | string) => Number(id));
});

function isConnectionSelected(id: number): boolean {
    return selectedConnectionIds.value.includes(id);
}

type AdsQueryPayload = Record<string, string | number | Array<string | number>>;

function queryBase(overrides: Record<string, unknown> = {}): AdsQueryPayload {
    const f = filters.value;
    const period = (overrides.period as string | undefined) ?? f.period ?? 'last_30_days';
    const payload: AdsQueryPayload = {
        period,
        group_by: (overrides.group_by as string | undefined) ?? f.group_by ?? 'day',
    };

    const connectionIds = (overrides.connection_ids as number[] | undefined)
        ?? (f.connection_ids?.length ? f.connection_ids : undefined);
    if (connectionIds?.length && connectionIds.length < allConnectionIds.value.length) {
        payload.connection_ids = connectionIds;
    }

    const campaignIds = (overrides.campaign_ids as number[] | undefined) ?? f.campaign_ids;
    if (campaignIds?.length) payload.campaign_ids = campaignIds;

    const itemIds = (overrides.item_ids as string[] | undefined) ?? f.item_ids;
    if (itemIds?.length) payload.item_ids = itemIds;

    const productIds = (overrides.product_ids as number[] | undefined) ?? f.product_ids;
    if (productIds?.length) payload.product_ids = productIds;

    if (period === 'custom') {
        const from = (overrides.from as string | undefined) ?? f.from;
        const to = (overrides.to as string | undefined) ?? f.to;
        if (from) payload.from = from;
        if (to) payload.to = to;
    }

    return payload;
}

const selectedConnectionsLabel = computed(() => {
    const ids = selectedConnectionIds.value;
    if (!ids.length || ids.length === allConnectionIds.value.length) return 'Todas las cuentas';
    return props.connections
        .filter((c) => ids.includes(c.id))
        .map((c) => connectionFilterLabel(c))
        .join(', ');
});

function applyFilters(overrides: Record<string, unknown> = {}) {
    router.get(route('ads.dashboard'), queryBase(overrides), {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
}

function toggleConnection(id: number) {
    const current = [...selectedConnectionIds.value];
    const idx = current.indexOf(id);
    if (idx >= 0) {
        if (current.length === 1) return;
        current.splice(idx, 1);
    } else {
        current.push(id);
    }
    applyFilters({
        connection_ids: current.length === allConnectionIds.value.length ? [] : current,
    });
}

function formatPct(value: number | null | undefined): string {
    if (value == null || !Number.isFinite(Number(value))) return '—';
    return `${(Number(value) * 100).toFixed(1)}%`;
}

function formatRoas(value: number | null | undefined): string {
    if (value == null || !Number.isFinite(Number(value))) return '—';
    return `${Number(value).toFixed(2)}x`;
}

const chartOption = computed(() => {
    const series = props.metrics.series || [];
    return {
        tooltip: { trigger: 'axis' },
        legend: { data: ['Inversión', 'Revenue atribuido'] },
        grid: { left: 48, right: 24, top: 40, bottom: 28 },
        xAxis: {
            type: 'category',
            data: series.map((s) => s.label),
        },
        yAxis: { type: 'value' },
        series: [
            {
                name: 'Inversión',
                type: 'line',
                smooth: true,
                data: series.map((s) => s.cost),
                itemStyle: { color: '#0f766e' },
            },
            {
                name: 'Revenue atribuido',
                type: 'line',
                smooth: true,
                data: series.map((s) => s.attributed_revenue),
                itemStyle: { color: '#0369a1' },
            },
        ],
    };
});

function drillRow(row: Record<string, any>) {
    const meta = row.meta || {};
    const overrides: Record<string, unknown> = {};
    if (meta.campaign_id) overrides.campaign_ids = [meta.campaign_id];
    if (meta.connection_id) overrides.connection_ids = [meta.connection_id];
    if (meta.item_id) overrides.item_ids = [meta.item_id];
    if (meta.product_id) overrides.product_ids = [meta.product_id];
    if (Object.keys(overrides).length) applyFilters(overrides);
}
</script>

<template>
    <Head title="Publicidad" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Publicidad"
                    description="Inversión en Product Ads, ROAS/ACoS y cruce por campaña, publicación o producto."
                >
                    <template #actions>
                        <Link
                            :href="route('ads.assistant')"
                            class="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-800"
                        >
                            Asistente
                        </Link>
                        <Link
                            :href="route('connections.index')"
                            class="text-sm text-teal-700 hover:underline"
                        >
                            Configurar sync Ads
                        </Link>
                    </template>
                </PageHeader>

                <Card content-class="p-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="opt in periodOptions"
                                :key="opt.value"
                                type="button"
                                class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                                :class="
                                    filters.period === opt.value
                                        ? 'bg-teal-700 text-white'
                                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                "
                                @click="applyFilters({ period: opt.value })"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="text-xs text-slate-500">Agrupar por</label>
                            <select
                                class="rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm"
                                :value="filters.group_by || 'day'"
                                @change="applyFilters({ group_by: ($event.target as HTMLSelectElement).value })"
                            >
                                <option
                                    v-for="opt in groupByOptions"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </option>
                            </select>
                            <select
                                class="min-w-[10rem] rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm"
                                :value="(filters.campaign_ids || [])[0] || ''"
                                @change="
                                    applyFilters({
                                        campaign_ids: ($event.target as HTMLSelectElement).value
                                            ? [Number(($event.target as HTMLSelectElement).value)]
                                            : [],
                                    })
                                "
                            >
                                <option value="">Todas las campañas</option>
                                <option
                                    v-for="c in metrics.campaigns"
                                    :key="c.id"
                                    :value="c.id"
                                >
                                    {{ c.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div
                        v-if="connections.length > 1"
                        class="mt-4 flex flex-wrap gap-2"
                    >
                        <button
                            v-for="c in connections"
                            :key="c.id"
                            type="button"
                            class="rounded-full transition"
                            :class="isConnectionSelected(c.id) ? 'opacity-100' : 'opacity-40'"
                            @click="toggleConnection(c.id)"
                        >
                            <ConnectionChip :connection="c" />
                        </button>
                    </div>

                    <p class="mt-3 text-xs text-slate-500">
                        {{ metrics.period.label }} · {{ metrics.period.start }} → {{ metrics.period.end }}
                        <span v-if="connections.length">
                            · {{ selectedConnectionsLabel }}
                        </span>
                    </p>
                </Card>

                <div
                    v-if="!metrics.has_data"
                    class="rounded-lg border border-dashed px-6 py-10 text-center"
                    :class="syncStatus?.permission_blocked ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50'"
                >
                    <Megaphone class="mx-auto h-8 w-8 text-slate-400" />
                    <p class="mt-3 text-sm font-medium text-slate-800">
                        {{
                            syncStatus?.permission_blocked
                                ? 'Mercado Libre bloqueó el acceso a Product Ads'
                                : 'Sin datos de publicidad en este período'
                        }}
                    </p>
                    <div
                        v-if="syncStatus?.permission_blocked"
                        class="mx-auto mt-3 max-w-xl space-y-2 text-left text-sm text-slate-700"
                    >
                        <p>
                            La API respondió
                            <code class="rounded bg-white px-1 py-0.5 text-xs">403 PA_UNAUTHORIZED_RESULT_FROM_POLICIES</code>.
                            La app OAuth no tiene el permiso funcional de Publicidad.
                        </p>
                        <ol class="list-decimal space-y-1 pl-5 text-slate-600">
                            <li>
                                Entrá a
                                <a
                                    href="https://developers.mercadolibre.com.mx/devcenter"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-medium text-teal-700 hover:underline"
                                >Mercado Libre Developers</a>
                                → tu aplicación → <strong>Permisos funcionales</strong>.
                            </li>
                            <li>Habilitá <strong>Publicidad</strong> (lectura; escritura opcional).</li>
                            <li>Reautorizá la conexión en esta app y sincronizá Ads de nuevo.</li>
                        </ol>
                        <p
                            v-if="syncStatus?.last_run?.error"
                            class="rounded-md border border-amber-200 bg-white px-3 py-2 text-xs text-slate-600"
                        >
                            {{ syncStatus.last_run.error }}
                        </p>
                    </div>
                    <template v-else>
                        <p class="mt-1 text-sm text-slate-500">
                            Conexiones con Ads activo:
                            {{ syncStatus?.ads_enabled_connections ?? 0 }} /
                            {{ syncStatus?.connections_total ?? connections.length }}.
                            Activá el recurso Publicidad / Ads y ejecutá sync bootstrap.
                        </p>
                        <p
                            v-if="syncStatus?.last_run?.status === 'failed' && syncStatus?.last_run?.error"
                            class="mx-auto mt-3 max-w-xl rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-left text-xs text-rose-700"
                        >
                            Último sync falló: {{ syncStatus.last_run.error }}
                        </p>
                    </template>
                    <Link
                        :href="route('connections.index')"
                        class="mt-4 inline-block text-sm font-medium text-teal-700 hover:underline"
                    >
                        Ir a conexiones
                    </Link>
                </div>

                <template v-else>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                        <Card content-class="p-4">
                            <p class="text-xs text-slate-500">Inversión</p>
                            <p class="mt-1 text-xl font-semibold">
                                <MoneyText :amount="kpis.cost" :currency="currency" />
                            </p>
                        </Card>
                        <Card content-class="p-4">
                            <p class="text-xs text-slate-500">Revenue atribuido ML</p>
                            <p class="mt-1 text-xl font-semibold">
                                <MoneyText :amount="kpis.attributed_revenue" :currency="currency" />
                            </p>
                        </Card>
                        <Card content-class="p-4">
                            <p class="text-xs text-slate-500">ROAS / ACoS</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums">
                                {{ formatRoas(kpis.roas) }}
                                <span class="text-sm font-normal text-slate-500">
                                    / {{ formatPct(kpis.acos) }}
                                </span>
                            </p>
                        </Card>
                        <Card content-class="p-4">
                            <p class="text-xs text-slate-500">Clics · CPC</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums">
                                {{ kpis.clicks ?? 0 }}
                                <span class="text-sm font-normal text-slate-500">
                                    ·
                                    <MoneyText :amount="kpis.cpc" :currency="currency" />
                                </span>
                            </p>
                        </Card>
                        <Card content-class="p-4">
                            <p class="text-xs text-slate-500">Ads en P&L (estimado)</p>
                            <p class="mt-1 text-xl font-semibold">
                                <MoneyText :amount="kpis.pnl_ads" :currency="currency" />
                            </p>
                            <p class="mt-1 text-[11px] text-slate-500">
                                Δ vs inversión:
                                <MoneyText :amount="kpis.pnl_ads_delta" :currency="currency" />
                            </p>
                        </Card>
                    </div>

                    <Card content-class="p-4">
                        <h3 class="mb-2 text-sm font-semibold text-slate-900">
                            Inversión vs revenue atribuido
                        </h3>
                        <DashboardChart :option="chartOption" height="280px" />
                    </Card>

                    <Card content-class="p-0 overflow-hidden">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <h3 class="text-sm font-semibold text-slate-900">
                                Desglose
                            </h3>
                            <p class="text-xs text-slate-500">
                                Clic en una fila para filtrar (drill-down).
                            </p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">Nombre</th>
                                        <th class="px-4 py-2.5 font-medium text-right">Inversión</th>
                                        <th class="px-4 py-2.5 font-medium text-right">Revenue atr.</th>
                                        <th class="px-4 py-2.5 font-medium text-right">ROAS</th>
                                        <th class="px-4 py-2.5 font-medium text-right">ACoS</th>
                                        <th class="px-4 py-2.5 font-medium text-right">Clics</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in metrics.breakdown"
                                        :key="row.key"
                                        class="cursor-pointer border-t border-slate-100 hover:bg-teal-50/40"
                                        @click="drillRow(row)"
                                    >
                                        <td class="px-4 py-2.5 font-medium text-slate-800">
                                            {{ row.label }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">
                                            <MoneyText :amount="row.cost" :currency="currency" />
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">
                                            <MoneyText :amount="row.attributed_revenue" :currency="currency" />
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">
                                            {{ formatRoas(row.roas) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">
                                            {{ formatPct(row.acos) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">
                                            {{ row.clicks }}
                                        </td>
                                    </tr>
                                    <tr v-if="!metrics.breakdown.length">
                                        <td
                                            colspan="6"
                                            class="px-4 py-8 text-center text-slate-500"
                                        >
                                            Sin filas para este desglose.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Card>

                    <p class="text-xs text-slate-500">
                        ACoS blended interno (inversión / GMV local): {{ formatPct(kpis.blended_acos) }}.
                        El costo por orden en el P&L es una estimación (ACoS blended ítem/día), no un cobro exacto de ML.
                    </p>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
