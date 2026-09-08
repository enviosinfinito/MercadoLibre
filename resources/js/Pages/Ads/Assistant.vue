<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import AdsEntityDetailSlideOver from '@/Components/Ads/AdsEntityDetailSlideOver.vue';
import AdsPlainMetric from '@/Components/Ads/AdsPlainMetric.vue';
import AdsSourceLegend from '@/Components/Ads/AdsSourceLegend.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import SlideOverShell from '@/Components/ui/SlideOverShell.vue';
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar';
import { connectionSurfaceStyle } from '@/lib/connectionColor';
import { ADS_PDP, priorityLabelEs, statusLabelEs } from '@/lib/adsPdpCopy';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Bot, ChevronRight, Filter, Settings2, ShieldAlert, Sparkles } from 'lucide-vue-next';

interface ConnectionBrief {
    id: number;
    display_name?: string | null;
    external_user_id?: string | null;
    color?: string | null;
    provider?: string | null;
    label?: string | null;
    site_id?: string | null;
}

interface Proposal {
    id: number;
    title: string;
    reason: string;
    priority: string;
    action_type: string;
    estimated_impact_amount: number | null;
    ml_item_id?: string | null;
    connection_id?: number | null;
    connection?: ConnectionBrief | null;
    metrics_snapshot?: Record<string, any> | null;
    status: string;
}

const props = defineProps<{
    settings: {
        autopilot_mode: string;
        active_preset: string | null;
        kill_switch: boolean;
        write_enabled: boolean;
        write_failures: number;
    };
    presets: Array<{ key: string; label: string; description: string }>;
    scorecard: {
        period: { start: string; end: string; days: number };
        catalog_target_roas: number;
        catalog_target_acos: number;
        avg_margin_rate: number;
        ads_budget_share_of_margin: number;
        kpis: Record<string, number>;
        scorecard: Array<Record<string, any>>;
        traffic_lights: { green: number; yellow: number; red: number };
    };
    proposals: Proposal[];
    rules: Array<Record<string, any>>;
    history: Array<Record<string, any>>;
    connections: ConnectionBrief[];
    advertisers: Array<{ id: number; connection_id: number; label: string }>;
    wizard: {
        suggested_roas_target: number;
        groups: Array<{ key: string; label: string; items: Array<Record<string, any>> }>;
        write_enabled: boolean;
    };
    lookback_days?: number;
    lookbackOptions?: Array<{ value: number; label: string }>;
    writeEndpointsDoc?: { note?: string };
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as any)?.success as string | undefined);
const flashError = computed(() => (page.props.flash as any)?.error as string | undefined);

const selectedIds = ref<number[]>([]);
const tab = ref<'today' | 'explore' | 'rules' | 'history' | 'wizard'>('today');
const statusFilter = ref<'all' | 'red' | 'yellow' | 'green'>('all');
const connectionFilterIds = ref<number[]>([]);
const search = ref('');
const sortKey = ref<'cost' | 'roas' | 'attributed_revenue' | 'status'>('cost');
const setupOpen = ref(false);
const filtersOpen = ref(false);

const detailOpen = ref(false);
const detailItemId = ref<string | null>(null);
const detailConnectionId = ref<number | null>(null);

const setupForm = useForm({
    preset: props.settings.active_preset || 'protect_profit',
    autopilot_mode: props.settings.autopilot_mode || 'shadow',
});

const settingsForm = useForm({
    autopilot_mode: props.settings.autopilot_mode,
    kill_switch: props.settings.kill_switch,
    write_enabled: props.settings.write_enabled,
});

const wizardForm = useForm({
    connection_id: props.connections[0]?.id ?? (null as number | null),
    advertiser_id: null as number | null,
    name: 'Rentables — asistente',
    daily_budget: 300,
    roas_target: props.wizard.suggested_roas_target || 5,
    item_ids: [] as string[],
    strategy: 'PROFITABILITY',
});

const currency = 'MXN';
const lights = computed(() => props.scorecard.traffic_lights);
const needsSetup = computed(() => !props.settings.active_preset && props.rules.length === 0);
const lookbackDays = computed(() => props.lookback_days || props.scorecard.period.days || 14);

const activeFilterCount = computed(() => {
    let n = 0;
    if (statusFilter.value !== 'all') n += 1;
    if (search.value.trim()) n += 1;
    if (connectionFilterIds.value.length) n += 1;
    if (sortKey.value !== 'cost') n += 1;
    return n;
});

const connectionFilterSummary = computed(() => {
    if (!connectionFilterIds.value.length) return 'Todos los canales';
    if (connectionFilterIds.value.length === 1) {
        const c = props.connections.find((x) => x.id === connectionFilterIds.value[0]);
        return c?.display_name || c?.label || c?.external_user_id || '1 canal';
    }
    return `${connectionFilterIds.value.length} canales`;
});

function clearExploreFilters() {
    statusFilter.value = 'all';
    search.value = '';
    connectionFilterIds.value = [];
    sortKey.value = 'cost';
}

function submitSetupAndClose() {
    setupForm.post(route('ads.assistant.setup'), {
        preserveScroll: true,
        onSuccess: () => {
            setupOpen.value = false;
        },
    });
}

const filteredScorecard = computed(() => {
    let rows = [...(props.scorecard.scorecard || [])];
    if (statusFilter.value !== 'all') {
        rows = rows.filter((r) => r.status === statusFilter.value);
    }
    if (connectionFilterIds.value.length) {
        const set = new Set(connectionFilterIds.value);
        rows = rows.filter((r) => set.has(Number(r.connection_id)));
    }
    const q = search.value.trim().toLowerCase();
    if (q) {
        rows = rows.filter(
            (r) =>
                String(r.ml_item_id || '').toLowerCase().includes(q) ||
                String(r.title || '').toLowerCase().includes(q) ||
                String(r.connection?.display_name || '').toLowerCase().includes(q),
        );
    }
    const rank: Record<string, number> = { red: 0, yellow: 1, green: 2 };
    rows.sort((a, b) => {
        if (sortKey.value === 'status') {
            return (rank[a.status] ?? 9) - (rank[b.status] ?? 9) || b.cost - a.cost;
        }
        if (sortKey.value === 'roas') return (a.roas ?? 0) - (b.roas ?? 0);
        if (sortKey.value === 'attributed_revenue') {
            return (b.attributed_revenue ?? 0) - (a.attributed_revenue ?? 0);
        }
        return (b.cost ?? 0) - (a.cost ?? 0);
    });
    return rows;
});

function toggleConnectionFilter(id: number) {
    const idx = connectionFilterIds.value.indexOf(id);
    if (idx >= 0) connectionFilterIds.value.splice(idx, 1);
    else connectionFilterIds.value.push(id);
}

function isConnectionFilterActive(id: number) {
    return connectionFilterIds.value.length === 0 || connectionFilterIds.value.includes(id);
}

function openItemDetail(row: Record<string, any>) {
    detailItemId.value = String(row.ml_item_id);
    detailConnectionId.value = row.connection_id ? Number(row.connection_id) : null;
    detailOpen.value = true;
}

function openSiblingItemDetail(payload: { mlItemId: string; connectionId?: number | null }) {
    detailItemId.value = payload.mlItemId;
    detailConnectionId.value = payload.connectionId ? Number(payload.connectionId) : detailConnectionId.value;
    detailOpen.value = true;
}

function openProposalDetail(p: Proposal) {
    if (!p.ml_item_id) return;
    detailItemId.value = p.ml_item_id;
    detailConnectionId.value = p.connection_id ? Number(p.connection_id) : null;
    detailOpen.value = true;
}

function closeDetail() {
    detailOpen.value = false;
    detailItemId.value = null;
    detailConnectionId.value = null;
}

function changeLookback(days: number) {
    router.get(route('ads.assistant'), { days }, { preserveState: true, preserveScroll: true, replace: true });
}

function toggleProposal(id: number) {
    const idx = selectedIds.value.indexOf(id);
    if (idx >= 0) selectedIds.value.splice(idx, 1);
    else selectedIds.value.push(id);
}

function selectAllProposals() {
    selectedIds.value = props.proposals.map((p) => p.id);
}

function saveSettings() {
    settingsForm.put(route('ads.assistant.settings'), { preserveScroll: true });
}

function evaluateNow() {
    router.post(route('ads.assistant.evaluate'), {}, { preserveScroll: true });
}

function approveOne(id: number) {
    router.post(route('ads.assistant.proposals.approve', id), { execute: true }, { preserveScroll: true });
}

function rejectOne(id: number) {
    router.post(route('ads.assistant.proposals.reject', id), {}, { preserveScroll: true });
}

function approveSelected() {
    if (!selectedIds.value.length) return;
    router.post(
        route('ads.assistant.proposals.approve-bulk'),
        { proposal_ids: selectedIds.value },
        { preserveScroll: true, onSuccess: () => { selectedIds.value = []; } },
    );
}

function toggleRule(rule: Record<string, any>, field: 'enabled' | 'auto_execute') {
    router.put(route('ads.assistant.rules.update', rule.id), { [field]: !rule[field] }, { preserveScroll: true });
}

function undo(id: number) {
    router.post(route('ads.assistant.executions.undo', id), {}, { preserveScroll: true });
}

function probeWrite() {
    const connectionId = wizardForm.connection_id || props.connections[0]?.id;
    if (!connectionId) return;
    router.post(route('ads.assistant.probe-write'), { connection_id: connectionId }, { preserveScroll: true });
}

function fillWizardFromGroup(key: string) {
    const group = props.wizard.groups.find((g) => g.key === key);
    if (!group) return;
    wizardForm.item_ids = group.items.map((i) => String(i.ml_item_id));
    wizardForm.roas_target = props.wizard.suggested_roas_target;
    wizardForm.name =
        key === 'high_margin_winners' ? 'Rentables — scale' : key === 'needs_protection' ? 'Protección margen' : 'Estables';
}

function submitWizard() {
    wizardForm.post(route('ads.assistant.campaigns.create'), { preserveScroll: true });
}

function statusClass(status: string) {
    if (status === 'red') return 'bg-rose-100 text-rose-800';
    if (status === 'yellow') return 'bg-amber-100 text-amber-800';
    return 'bg-emerald-100 text-emerald-800';
}

function statusLabel(row: Record<string, any>) {
    return statusLabelEs(String(row.status || ''), row.status_label);
}

function roasTone(roas: number | null | undefined, target?: number | null, unreliable?: boolean) {
    if (unreliable) return 'text-slate-600';
    if (roas == null || !Number.isFinite(Number(roas))) return 'text-slate-500';
    const t = Number(target || 0);
    if (t > 0 && Number(roas) >= t) return 'text-emerald-700';
    if (t > 0 && Number(roas) >= t * 0.7) return 'text-amber-700';
    return 'text-rose-700';
}

function roasVsTargetLabel(roas: number | null | undefined, target?: number | null) {
    if (roas == null || target == null || !Number.isFinite(Number(roas)) || !(Number(target) > 0)) {
        return 'sin meta';
    }
    const pct = Math.round((Number(roas) / Number(target)) * 100);
    if (pct >= 100) return `✓ ${pct}% de la meta`;
    return `${pct}% de la meta`;
}

function priorityClass(p: string) {
    if (p === 'high') return 'text-rose-700';
    if (p === 'medium') return 'text-amber-700';
    return 'text-slate-600';
}

function formatPct(value: number | null | undefined): string {
    if (value == null || !Number.isFinite(Number(value))) return '—';
    return `${(Number(value) * 100).toFixed(1)}%`;
}

function targetDisplay(row: Record<string, any>): string {
    const t = Number(row.target_roas || 0).toFixed(2);
    if (row.target_unreliable) return `${t}x · estimada`;
    const m = row.margin_pct_display != null ? `${row.margin_pct_display}%` : null;
    return m ? `${t}x · margen ${m}` : `${t}x`;
}
</script>

<template>
    <Head title="Asistente de publicidad" />

    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="Asistente de publicidad"
                description="Te decimos qué publicaciones cuidar, en español, con tu margen real."
            >
                <template #actions>
                    <Link
                        :href="route('ads.dashboard')"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                    >
                        Dashboard Ads
                    </Link>
                    <button
                        type="button"
                        class="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-800"
                        @click="evaluateNow"
                    >
                        Re-evaluar ahora
                    </button>
                </template>
            </PageHeader>
        </template>

        <div class="space-y-4">
            <div
                v-if="flashSuccess"
                class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ flashSuccess }}
            </div>
            <div
                v-if="flashError"
                class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ flashError }}
            </div>

            <Card class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <div class="rounded-lg bg-teal-50 p-2 text-teal-800">
                            <Bot class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-900">
                                Meta catálogo para cuidar ganancia: {{ scorecard.catalog_target_roas }}x
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                Ganancia promedio {{ (scorecard.avg_margin_rate * 100).toFixed(0) }}% ·
                                ventana {{ scorecard.period.days }}d ({{ scorecard.period.start }} →
                                {{ scorecard.period.end }})
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <button
                            type="button"
                            class="rounded-full bg-emerald-100 px-2.5 py-1 text-emerald-800 hover:ring-2 hover:ring-emerald-300"
                            @click="statusFilter = 'green'; tab = 'explore'"
                        >
                            Bien {{ lights.green }}
                        </button>
                        <button
                            type="button"
                            class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-800 hover:ring-2 hover:ring-amber-300"
                            @click="statusFilter = 'yellow'; tab = 'explore'"
                        >
                            Cuidado {{ lights.yellow }}
                        </button>
                        <button
                            type="button"
                            class="rounded-full bg-rose-100 px-2.5 py-1 text-rose-800 hover:ring-2 hover:ring-rose-300"
                            @click="statusFilter = 'red'; tab = 'explore'"
                        >
                            Actuar {{ lights.red }}
                        </button>
                    </div>
                </div>

                <div class="mt-3">
                    <AdsSourceLegend />
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <AdsPlainMetric
                        :label="ADS_PDP.roas.label"
                        source="ours"
                        :plain="ADS_PDP.roas.plain"
                        :why="ADS_PDP.roas.why"
                        :how="ADS_PDP.roas.how"
                    >
                        {{ Number(scorecard.kpis.blended_roas || 0).toFixed(2) }}x
                        <span class="text-sm font-normal text-slate-500">/ {{ scorecard.catalog_target_roas }}x</span>
                    </AdsPlainMetric>
                    <AdsPlainMetric
                        :label="ADS_PDP.waste.label"
                        source="ours"
                        :plain="ADS_PDP.waste.plain"
                        :why="ADS_PDP.waste.why"
                        :how="ADS_PDP.waste.how"
                        :class="(scorecard.kpis.waste_spend || 0) > 0 ? 'ring-rose-100 bg-rose-50/80' : ''"
                    >
                        <MoneyText
                            :amount="scorecard.kpis.waste_spend || 0"
                            :currency="currency"
                            :class="(scorecard.kpis.waste_spend || 0) > 0 ? 'text-rose-800' : ''"
                        />
                    </AdsPlainMetric>
                    <AdsPlainMetric
                        :label="ADS_PDP.spend.label"
                        source="ml"
                        :plain="ADS_PDP.spend.plain"
                        :why="ADS_PDP.spend.why"
                        :how="ADS_PDP.spend.how"
                    >
                        <MoneyText :amount="scorecard.kpis.spend || 0" :currency="currency" />
                    </AdsPlainMetric>
                    <AdsPlainMetric
                        :label="ADS_PDP.tacos.label"
                        source="ours"
                        :plain="ADS_PDP.tacos.plain"
                        :why="ADS_PDP.tacos.why"
                        :how="ADS_PDP.tacos.how"
                    >
                        {{ ((scorecard.kpis.tacos || 0) * 100).toFixed(1) }}%
                    </AdsPlainMetric>
                </div>
            </Card>

            <Card class="p-3">
                <div class="flex flex-nowrap items-center gap-2 overflow-x-auto">
                    <div class="flex shrink-0 gap-1">
                        <button
                            v-for="t in [
                                { id: 'today', label: 'Hoy' },
                                { id: 'explore', label: 'Explorar' },
                                { id: 'rules', label: 'Reglas' },
                                { id: 'history', label: 'Historial' },
                                { id: 'wizard', label: 'Campaña' },
                            ]"
                            :key="t.id"
                            type="button"
                            class="rounded-md px-2.5 py-1.5 text-xs font-medium whitespace-nowrap transition"
                            :class="
                                tab === t.id
                                    ? 'bg-slate-900 text-white'
                                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                            "
                            @click="tab = t.id as any"
                        >
                            {{ t.label }}
                        </button>
                    </div>

                    <div class="mx-1 hidden h-5 w-px shrink-0 bg-slate-200 sm:block" />

                    <template v-if="tab === 'explore'">
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Buscar…"
                            class="min-w-[8rem] max-w-[12rem] flex-1 rounded-md border border-slate-200 px-2 py-1.5 text-xs"
                        />
                        <button
                            type="button"
                            class="inline-flex shrink-0 items-center gap-1 rounded-md border px-2 py-1.5 text-xs font-medium"
                            :class="
                                activeFilterCount
                                    ? 'border-teal-600 bg-teal-50 text-teal-900'
                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                            "
                            @click="filtersOpen = true"
                        >
                            <Filter class="h-3.5 w-3.5" />
                            Filtros
                            <span
                                v-if="activeFilterCount"
                                class="rounded-full bg-teal-700 px-1.5 text-[10px] text-white"
                            >
                                {{ activeFilterCount }}
                            </span>
                        </button>
                    </template>

                    <select
                        class="shrink-0 rounded-md border border-slate-200 bg-white px-2 py-1.5 text-xs"
                        :value="lookbackDays"
                        @change="changeLookback(Number(($event.target as HTMLSelectElement).value))"
                    >
                        <option
                            v-for="opt in lookbackOptions || [
                                { value: 7, label: '7d' },
                                { value: 14, label: '14d' },
                                { value: 30, label: '30d' },
                            ]"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>

                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-200 px-2 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                        @click="filtersOpen = true"
                    >
                        <Settings2 class="h-3.5 w-3.5" />
                        <span class="hidden sm:inline">Avanzado</span>
                    </button>

                    <button
                        v-if="needsSetup"
                        type="button"
                        class="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-md bg-teal-700 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-teal-800"
                        @click="setupOpen = true"
                    >
                        <Sparkles class="h-3.5 w-3.5" />
                        Setup guiado
                    </button>
                    <button
                        v-else
                        type="button"
                        class="ml-auto inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-200 px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
                        @click="setupOpen = true"
                    >
                        <Sparkles class="h-3.5 w-3.5" />
                        Preset
                    </button>
                </div>

                    <div v-if="tab === 'explore'" class="mt-3 space-y-3">
                        <div
                            v-if="!filteredScorecard.length"
                            class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500"
                        >
                            Sin publicaciones en este filtro. Sincronizá Ads o ampliá la ventana.
                        </div>
                        <div v-else class="overflow-x-auto rounded-lg border border-slate-200">
                            <table class="min-w-full text-left text-xs">
                                <thead class="bg-slate-50 text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2 font-medium">Publicación</th>
                                        <th class="px-3 py-2 font-medium">Canal</th>
                                        <th class="px-3 py-2 font-medium">Estado</th>
                                        <th class="px-3 py-2 text-right font-medium">Gasto</th>
                                        <th class="px-3 py-2 text-right font-medium">Ventas por ads</th>
                                        <th class="px-3 py-2 text-right font-medium">Ventas / $1</th>
                                        <th class="px-3 py-2 text-right font-medium">Meta ganancia</th>
                                        <th class="px-3 py-2 text-right font-medium">Uds</th>
                                        <th class="px-3 py-2 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in filteredScorecard"
                                        :key="row.ml_item_id + '-' + row.connection_id"
                                        class="conn-row cursor-pointer border-t border-slate-100"
                                        :style="connectionSurfaceStyle(row.connection?.color)"
                                        @click="openItemDetail(row)"
                                    >
                                        <td class="max-w-[18rem] px-3 py-2">
                                            <p class="truncate font-medium text-slate-800">
                                                {{ row.title || row.ml_item_id }}
                                            </p>
                                            <p class="truncate text-[11px] text-slate-400">{{ row.ml_item_id }}</p>
                                        </td>
                                        <td class="px-3 py-2">
                                            <ConnectionChip
                                                v-if="row.connection"
                                                :connection="row.connection"
                                            />
                                            <span v-else class="text-slate-400">—</span>
                                        </td>
                                        <td class="px-3 py-2">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="statusClass(row.status)"
                                            >
                                                {{ statusLabel(row) }}
                                            </span>
                                            <p
                                                v-if="row.status_reason"
                                                class="mt-1 max-w-[14rem] text-[10px] leading-snug text-slate-500"
                                            >
                                                {{ row.status_reason }}
                                            </p>
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <MoneyText :amount="row.cost" :currency="currency" />
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <MoneyText :amount="row.attributed_revenue" :currency="currency" />
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <p
                                                class="tabular-nums font-semibold"
                                                :class="roasTone(row.roas, row.target_roas, row.target_unreliable)"
                                            >
                                                {{ Number(row.roas).toFixed(2) }}x
                                            </p>
                                            <p class="text-[10px] text-slate-400">
                                                <template v-if="row.target_unreliable">meta provisional</template>
                                                <template v-else>{{ roasVsTargetLabel(row.roas, row.target_roas) }}</template>
                                            </p>
                                        </td>
                                        <td class="px-3 py-2 text-right tabular-nums text-slate-500">
                                            {{ targetDisplay(row) }}
                                        </td>
                                        <td class="px-3 py-2 text-right tabular-nums">
                                            {{ row.attributed_units }}
                                        </td>
                                        <td class="px-3 py-2 text-slate-400">
                                            <ChevronRight class="h-4 w-4" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-else-if="tab === 'today'" class="mt-4 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm text-slate-600">
                            {{ proposals.length }} acciones para hoy. Cada una dice el porqué y cuánta plata dejás de gastar.
                        </p>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 px-2.5 py-1 text-xs hover:bg-slate-50"
                                @click="selectAllProposals"
                            >
                                Seleccionar todas
                            </button>
                            <button
                                type="button"
                                class="rounded-md bg-teal-700 px-2.5 py-1 text-xs font-medium text-white disabled:opacity-40"
                                :disabled="!selectedIds.length"
                                @click="approveSelected"
                            >
                                Aplicar seleccionadas
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="!proposals.length"
                        class="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500"
                    >
                        No hay propuestas pendientes. Instalá un preset o re-evaluá las reglas.
                    </div>

                    <div
                        v-for="p in proposals"
                        :key="p.id"
                        class="conn-row flex flex-wrap items-start gap-3 rounded-lg border border-slate-200 p-3"
                        :style="connectionSurfaceStyle(p.connection?.color)"
                    >
                        <input
                            type="checkbox"
                            class="mt-1"
                            :checked="selectedIds.includes(p.id)"
                            @change="toggleProposal(p.id)"
                        />
                        <button
                            type="button"
                            class="min-w-0 flex-1 text-left"
                            @click="openProposalDetail(p)"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-slate-900 hover:text-teal-800">
                                    {{ p.title }}
                                </p>
                                <ConnectionChip
                                    v-if="p.connection"
                                    :connection="p.connection"
                                />
                                <span class="text-[11px] font-medium uppercase" :class="priorityClass(p.priority)">
                                    {{ priorityLabelEs(p.priority) }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-slate-600">{{ p.reason }}</p>
                            <p v-if="p.estimated_impact_amount != null" class="mt-1 text-xs text-slate-500">
                                Si hacés esto, dejás de gastar ~
                                <MoneyText :amount="p.estimated_impact_amount" :currency="currency" />
                                en algo que no rinde
                                <span
                                    v-if="p.metrics_snapshot?.estimated_margin_protected != null"
                                    class="text-teal-700"
                                >
                                    · protege ~${{ Number(p.metrics_snapshot.estimated_margin_protected).toFixed(0) }} de margen
                                </span>
                            </p>
                            <p v-if="p.ml_item_id" class="mt-1 text-[11px] text-teal-700">
                                Ver detalle →
                            </p>
                        </button>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-md bg-teal-700 px-2.5 py-1 text-xs font-medium text-white"
                                @click="approveOne(p.id)"
                            >
                                Aplicar
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 px-2.5 py-1 text-xs hover:bg-slate-50"
                                @click="rejectOne(p.id)"
                            >
                                Descartar
                            </button>
                        </div>
                    </div>

                    <p class="pt-1 text-xs text-slate-500">
                        Para el scorecard completo con revenue y drill-down, usá la pestaña
                        <button type="button" class="font-medium text-teal-700 underline" @click="tab = 'explore'">
                            Explorar
                        </button>.
                    </p>
                </div>

                <div v-else-if="tab === 'rules'" class="mt-4 space-y-2">
                    <p class="text-sm text-slate-600">
                        Reglas legibles (condición + acción). El autopilot solo ejecuta las marcadas con auto.
                    </p>
                    <div
                        v-if="!rules.length"
                        class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500"
                    >
                        Todavía no hay reglas. Completá el setup guiado.
                    </div>
                    <div
                        v-for="rule in rules"
                        :key="rule.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-3"
                    >
                        <div>
                            <p class="text-sm font-semibold">{{ rule.name }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ rule.description }}</p>
                            <p class="mt-1 text-[11px] text-slate-400">
                                {{ rule.code }} · acción {{ rule.action_type }} · {{ rule.lookback_days }}d
                            </p>
                        </div>
                        <div class="flex items-center gap-4 text-xs">
                            <label class="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    :checked="rule.enabled"
                                    @change="toggleRule(rule, 'enabled')"
                                />
                                Activa
                            </label>
                            <label class="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    :checked="rule.auto_execute"
                                    @change="toggleRule(rule, 'auto_execute')"
                                />
                                Auto
                            </label>
                        </div>
                    </div>
                    <div v-if="!needsSetup" class="pt-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                            @click="setupOpen = true"
                        >
                            <Sparkles class="h-3.5 w-3.5 text-teal-700" />
                            Cambiar preset
                        </button>
                    </div>
                    <div v-else class="pt-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md bg-teal-700 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-teal-800"
                            @click="setupOpen = true"
                        >
                            <Sparkles class="h-3.5 w-3.5" />
                            Completar setup guiado
                        </button>
                    </div>
                </div>

                <div v-else-if="tab === 'history'" class="mt-4 space-y-2">
                    <div
                        v-if="!history.length"
                        class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500"
                    >
                        Sin ejecuciones todavía.
                    </div>
                    <div
                        v-for="h in history"
                        :key="h.id"
                        class="conn-row flex flex-wrap items-start justify-between gap-3 rounded-lg border border-slate-200 p-3 text-sm"
                        :style="connectionSurfaceStyle(h.connection?.color)"
                    >
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium">
                                    {{ h.action_type }}
                                    <span class="text-xs font-normal text-slate-500">· {{ h.status }}</span>
                                </p>
                                <ConnectionChip
                                    v-if="h.connection"
                                    :connection="h.connection"
                                />
                            </div>
                            <p class="mt-1 text-xs text-slate-600">{{ h.reason }}</p>
                            <p class="mt-1 text-[11px] text-slate-400">
                                {{ h.entity_type }} {{ h.entity_id }}
                                <span v-if="h.wrote_to_ml"> · escribió en ML</span>
                                · {{ h.created_at }}
                            </p>
                            <p v-if="h.error" class="mt-1 text-xs text-rose-600">{{ h.error }}</p>
                        </div>
                        <button
                            v-if="h.can_undo && !h.undone_at"
                            type="button"
                            class="rounded-md border border-slate-200 px-2.5 py-1 text-xs hover:bg-slate-50"
                            @click="undo(h.id)"
                        >
                            Deshacer
                        </button>
                    </div>
                </div>

                <div v-else class="mt-4 space-y-4">
                    <div class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" />
                        <p>
                            Wizard de campaña personalizada. Si Write ML está off, genera checklist para crear en el
                            panel de Mercado Ads. {{ writeEndpointsDoc?.note }}
                        </p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-3">
                        <button
                            v-for="g in wizard.groups"
                            :key="g.key"
                            type="button"
                            class="rounded-lg border border-slate-200 p-3 text-left hover:border-teal-500"
                            @click="fillWizardFromGroup(g.key)"
                        >
                            <p class="text-sm font-semibold">{{ g.label }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ g.items.length }} publicaciones</p>
                        </button>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="text-xs text-slate-600">
                            Conexión
                            <select
                                v-model="wizardForm.connection_id"
                                class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1.5 text-sm"
                            >
                                <option v-for="c in connections" :key="c.id" :value="c.id">
                                    {{ c.label }}
                                </option>
                            </select>
                        </label>
                        <label class="text-xs text-slate-600">
                            Nombre
                            <input
                                v-model="wizardForm.name"
                                type="text"
                                class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1.5 text-sm"
                            />
                        </label>
                        <label class="text-xs text-slate-600">
                            Presupuesto diario
                            <input
                                v-model.number="wizardForm.daily_budget"
                                type="number"
                                min="1"
                                class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1.5 text-sm"
                            />
                        </label>
                        <label class="text-xs text-slate-600">
                            ROAS objetivo
                            <input
                                v-model.number="wizardForm.roas_target"
                                type="number"
                                min="1"
                                max="35"
                                step="0.1"
                                class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1.5 text-sm"
                            />
                        </label>
                    </div>
                    <p class="text-xs text-slate-500">
                        Ítems seleccionados: {{ wizardForm.item_ids.length }}
                        <span v-if="wizardForm.item_ids.length">
                            ({{ wizardForm.item_ids.slice(0, 5).join(', ')
                            }}{{ wizardForm.item_ids.length > 5 ? '…' : '' }})
                        </span>
                    </p>
                    <button
                        type="button"
                        class="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-50"
                        :disabled="wizardForm.processing || !wizardForm.connection_id"
                        @click="submitWizard"
                    >
                        {{ settings.write_enabled ? 'Crear campaña en ML' : 'Generar checklist' }}
                    </button>
                </div>
            </Card>
        </div>

        <AdsEntityDetailSlideOver
            :show="detailOpen"
            :ml-item-id="detailItemId"
            :connection-id="detailConnectionId"
            :days="lookbackDays"
            @close="closeDetail"
            @select-item="openSiblingItemDetail"
            @approve-proposal="approveOne"
        />

        <SlideOverShell
            :show="setupOpen"
            title="Setup guiado"
            accessibility-title="Setup guiado de publicidad"
            @close="setupOpen = false"
        >
            <div class="space-y-4 overflow-y-auto p-4">
                <p class="text-sm text-slate-600">
                    Elegí un preset: genera reglas legibles y propuestas ancladas a tu ganancia.
                </p>
                <div class="flex flex-col gap-2">
                    <button
                        v-for="preset in presets"
                        :key="preset.key"
                        type="button"
                        class="flex items-start gap-3 rounded-lg border px-3 py-2.5 text-left transition"
                        :class="
                            setupForm.preset === preset.key
                                ? 'border-teal-600 bg-teal-50/60 shadow-sm'
                                : 'border-slate-200 hover:border-slate-300'
                        "
                        @click="setupForm.preset = preset.key"
                    >
                        <span
                            class="mt-0.5 h-4 w-4 shrink-0 rounded-full border-2"
                            :class="
                                setupForm.preset === preset.key
                                    ? 'border-teal-700 bg-teal-700'
                                    : 'border-slate-300'
                            "
                        />
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-slate-900">{{ preset.label }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">{{ preset.description }}</span>
                        </span>
                    </button>
                </div>
                <label class="block text-xs font-medium text-slate-600">
                    Modo inicial
                    <select
                        v-model="setupForm.autopilot_mode"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm"
                    >
                        <option value="shadow">Shadow (solo sugiere)</option>
                        <option value="approve">Aprobar antes de ejecutar</option>
                        <option value="auto">Auto (reglas con auto-ejecución)</option>
                    </select>
                </label>
            </div>
            <template #footer>
                <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
                    <template #end>
                        <ActionGroup>
                            <ActionButton variant="secondary" @click="setupOpen = false">Cancelar</ActionButton>
                            <ActionButton
                                variant="primary"
                                :disabled="setupForm.processing"
                                @click="submitSetupAndClose"
                            >
                                Instalar preset
                            </ActionButton>
                        </ActionGroup>
                    </template>
                </ActionBar>
            </template>
        </SlideOverShell>

        <SlideOverShell
            :show="filtersOpen"
            title="Filtros y avanzado"
            accessibility-title="Filtros del asistente de publicidad"
            @close="filtersOpen = false"
        >
            <div class="space-y-5 overflow-y-auto p-4">
                <section class="space-y-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Explorar</h3>
                    <label class="block text-xs text-slate-600">
                        Buscar
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Ítem, título o canal…"
                            class="mt-1 w-full rounded-md border border-slate-200 px-2.5 py-2 text-sm"
                        />
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block text-xs text-slate-600">
                            Estado
                            <select
                                v-model="statusFilter"
                                class="mt-1 w-full rounded-md border border-slate-200 px-2 py-2 text-sm"
                            >
                                <option value="all">Todos ({{ scorecard.scorecard.length }})</option>
                                <option value="red">Actuar ({{ lights.red }})</option>
                                <option value="yellow">Cuidado ({{ lights.yellow }})</option>
                                <option value="green">Bien ({{ lights.green }})</option>
                            </select>
                        </label>
                        <label class="block text-xs text-slate-600">
                            Orden
                            <select
                                v-model="sortKey"
                                class="mt-1 w-full rounded-md border border-slate-200 px-2 py-2 text-sm"
                            >
                                <option value="cost">Gasto</option>
                                <option value="attributed_revenue">Ventas por ads</option>
                                <option value="roas">Ventas / $1 ↑</option>
                                <option value="status">Semáforo</option>
                            </select>
                        </label>
                    </div>
                    <div v-if="connections.length > 1" class="space-y-2">
                        <p class="text-xs text-slate-600">
                            Canales · {{ connectionFilterSummary }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="c in connections"
                                :key="c.id"
                                type="button"
                                class="rounded-full transition"
                                :class="isConnectionFilterActive(c.id) ? 'opacity-100 ring-2 ring-teal-500 ring-offset-1' : 'opacity-40'"
                                @click="toggleConnectionFilter(c.id)"
                            >
                                <ConnectionChip :connection="c" />
                            </button>
                        </div>
                        <button
                            v-if="connectionFilterIds.length"
                            type="button"
                            class="text-[11px] font-medium text-teal-700"
                            @click="connectionFilterIds = []"
                        >
                            Ver todos los canales
                        </button>
                    </div>
                </section>

                <section class="space-y-2 border-t border-slate-200 pt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Avanzado (equipo técnico)
                    </h3>
                    <label class="block text-xs text-slate-600">
                        Modo autopilot
                        <select
                            v-model="settingsForm.autopilot_mode"
                            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-2 py-2 text-sm"
                            @change="saveSettings"
                        >
                            <option value="shadow">Shadow</option>
                            <option value="approve">Approve</option>
                            <option value="auto">Auto</option>
                        </select>
                    </label>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2">
                            <input v-model="settingsForm.write_enabled" type="checkbox" @change="saveSettings" />
                            Write ML
                        </label>
                        <label class="flex items-center gap-2 text-rose-700">
                            <input v-model="settingsForm.kill_switch" type="checkbox" @change="saveSettings" />
                            Kill switch
                        </label>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs hover:bg-slate-50"
                        @click="probeWrite"
                    >
                        Probar write
                    </button>
                    <p class="text-[11px] text-slate-500">
                        Modo {{ settings.autopilot_mode }} · Write {{ settings.write_enabled ? 'ON' : 'OFF' }}
                        <span v-if="settings.kill_switch" class="text-rose-600"> · kill switch</span>
                        · {{ scorecard.kpis.items || scorecard.scorecard.length }} publicaciones
                    </p>
                </section>
            </div>
            <template #footer>
                <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
                    <template #end>
                        <ActionGroup>
                            <ActionButton variant="secondary" @click="clearExploreFilters">Limpiar</ActionButton>
                            <ActionButton variant="primary" @click="filtersOpen = false">Listo</ActionButton>
                        </ActionGroup>
                    </template>
                </ActionBar>
            </template>
        </SlideOverShell>
    </AuthenticatedLayout>
</template>
