<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import DataTable from '@/Components/App/DataTable.vue';
import InfiniteListSummary from '@/Components/App/InfiniteListSummary.vue';
import StackedData from '@/Components/App/StackedData.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import FullOperationsSearchAndFilters from '@/Components/Inventory/FullOperationsSearchAndFilters.vue';
import FullOperationDetailSlideOver from '@/Components/Inventory/FullOperationDetailSlideOver.vue';
import StockDetailSlideOver from '@/Components/Inventory/StockDetailSlideOver.vue';
import FullOperationTypePill from '@/Components/Domain/FullOperationTypePill.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import TableSelectionCheckbox from '@/Components/ui/TableSelectionCheckbox.vue';
import RecordSelectionBar from '@/Components/ui/ListToolbar/RecordSelectionBar.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { buildExportSelectionPayload } from '@/composables/buildExportSelectionPayload';
import { useInfiniteList } from '@/composables/useInfiniteList';
import { useRecordSelection } from '@/composables/useRecordSelection';
import { connectionSurfaceStyle } from '@/lib/connectionColor';
import { formatSelectionNumeric } from '@/lib/selectionNumeric';
import { formatDateTime, formatRelativeShort } from '@/lib/utils';

type ConnectionHint = {
    id: number;
    display_name: string | null;
    provider?: string | null;
    external_user_id?: string | null;
    color?: string | null;
    status?: string;
};

type Entry = {
    id: number;
    external_operation_id: string;
    operation_type: string;
    operation_type_label: string;
    operation_type_family?: string;
    pill_variant?: 'success' | 'warning' | 'danger' | 'secondary' | 'muted' | 'default' | 'outline';
    occurred_at: string | null;
    inventory_id: string | null;
    available_quantity_delta: string | null;
    not_available_quantity_delta: string | null;
    result_available: string | null;
    result_not_available: string | null;
    result_total: string | null;
    external_references: Array<{ type?: string; value?: string; id?: string }>;
    variant: { id: number; sku: string; name: string | null } | null;
    connection: ConnectionHint | null;
};

type TodaySummary = {
    date: string;
    operations_today: number;
    operations_yesterday: number;
    net_available_today: number;
    net_available_yesterday: number;
    inbound_qty_today: number;
    inbound_qty_yesterday: number;
    sale_qty_today: number;
    sale_qty_yesterday: number;
};

const props = defineProps<{
    entries: {
        data: Entry[];
        total?: number;
        current_page?: number;
        last_page?: number;
        from?: number | null;
        to?: number | null;
        per_page?: number;
        next_page_url?: string | null;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    connections: ConnectionHint[];
    filters: {
        q: string;
        variant_id?: number | null;
        operation_type: string;
        tab?: string;
        connection_id: number | null;
        from: string;
        to: string;
    };
    operation_types: string[];
    operation_type_labels: Record<string, string>;
    family_labels?: Record<string, string>;
    type_counts?: Record<string, number>;
    today_summary?: TodaySummary;
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string } | undefined)?.success);

const familyLabels = computed(() => props.family_labels ?? {
    all: 'Todas',
    inbound: 'Ingreso',
    sale: 'Venta',
    return: 'Devolución',
    cancellation: 'Cancelación',
    transfer: 'Transferencia',
    quarantine: 'Cuarentena',
    withdrawal: 'Retiro',
    adjustment: 'Ajuste',
    other: 'Otros',
});

const typeCounts = computed(() => props.type_counts ?? {});
const todaySummary = computed<TodaySummary>(() => props.today_summary ?? {
    date: '',
    operations_today: 0,
    operations_yesterday: 0,
    net_available_today: 0,
    net_available_yesterday: 0,
    inbound_qty_today: 0,
    inbound_qty_yesterday: 0,
    sale_qty_today: 0,
    sale_qty_yesterday: 0,
});

const tabOrder = [
    'all',
    'inbound',
    'sale',
    'return',
    'cancellation',
    'transfer',
    'quarantine',
    'withdrawal',
    'adjustment',
    'other',
];

const tabs = computed(() =>
    tabOrder.map((key) => ({
        key,
        label: familyLabels.value[key] ?? key,
        count: Number(typeCounts.value[key] ?? 0),
    })),
);

const {
    displayItems,
    hasMorePages,
    totalCount,
    paginationFrom,
    paginationTo,
    isLoadingMore,
    loadMoreSentinel,
    resetAccumulation,
} = useInfiniteList({
    initialPaginator: computed(() => props.entries),
});

const rows = computed(() => (displayItems.value as Entry[]) ?? []);
const showingCount = computed(() => rows.value.length);

const summableColumns = computed(() => [
    { key: 'available_quantity_delta', label: 'Δ disp.', fieldMeta: { style: 'decimal' as const } },
    { key: 'not_available_quantity_delta', label: 'Δ no disp.', fieldMeta: { style: 'decimal' as const } },
]);

const {
    selectedIds,
    allPagesSelected,
    hasSelection,
    bulkSelectionProps,
    allLoadedSelected,
    someLoadedSelected,
    selectionNotice,
    isSelected,
    toggle,
    onHeaderCheckboxChange,
    selectAllInFilteredUniverse,
    clearSelection,
} = useRecordSelection({
    loadedRows: rows,
    totalCount,
    summableColumns,
    currentQuery: () => ({
        q: props.filters.q || undefined,
        operation_type: props.filters.operation_type || undefined,
        tab: props.filters.tab && props.filters.tab !== 'all' ? props.filters.tab : undefined,
        connection_id: props.filters.connection_id || undefined,
        from: props.filters.from || undefined,
        to: props.filters.to || undefined,
    }),
    endpoints: {
        allIds: () => route('inventory.full-operations.all-ids'),
        filteredSums: () => route('inventory.full-operations.filtered-sums'),
    },
    formatNumeric: (n, meta) =>
        formatSelectionNumeric(n, (meta ?? {}) as { style?: 'currency' | 'decimal' | 'integer' }),
    itemLabel: 'movimientos',
});

const detailOpen = ref(false);
const selectedOperationId = ref<number | null>(null);
const selectedConnection = ref<ConnectionHint | null>(null);
const selectedVariantId = ref<number | null>(null);

function openDetail(row: Entry) {
    selectedOperationId.value = row.id;
    selectedConnection.value = row.connection;
    detailOpen.value = true;
}

function closeDetail() {
    detailOpen.value = false;
    selectedOperationId.value = null;
    selectedConnection.value = null;
}

function beforeApplyFilters() {
    resetAccumulation();
}

function selectTab(tab: string) {
    resetAccumulation();
    router.get(
        route('inventory.full-operations.index'),
        {
            q: props.filters.q || undefined,
            connection_id: props.filters.connection_id || undefined,
            from: props.filters.from || undefined,
            to: props.filters.to || undefined,
            tab: tab === 'all' ? undefined : tab,
            page: 1,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

function filterToday() {
    const date = todaySummary.value.date;
    if (!date) return;
    resetAccumulation();
    router.get(
        route('inventory.full-operations.index'),
        {
            q: props.filters.q || undefined,
            connection_id: props.filters.connection_id || undefined,
            tab: props.filters.tab && props.filters.tab !== 'all' ? props.filters.tab : undefined,
            operation_type: props.filters.operation_type || undefined,
            from: date,
            to: date,
            page: 1,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

function syncNow() {
    router.post(route('inventory.full-operations.sync'), {
        days: 90,
        connection_id: props.filters.connection_id || undefined,
    });
}

function pctDelta(current: number, previous: number): number | null {
    if (previous === 0) {
        return current === 0 ? 0 : null;
    }
    return ((current - previous) / previous) * 100;
}

function formatPctDelta(delta: number | null): string {
    if (delta == null) return 'vs ayer';
    const sign = delta > 0 ? '+' : '';
    return `${sign}${delta.toFixed(1)}% vs ayer`;
}

function deltaClass(delta: number | null) {
    if (delta == null || delta === 0) return 'text-muted-foreground';
    return delta > 0 ? 'text-emerald-700' : 'text-rose-700';
}

function fmtDelta(v: string | number | null | undefined) {
    if (v == null || v === '') return '—';
    const n = Number(v);
    if (Number.isNaN(n)) return String(v);
    const formatted = n.toLocaleString('es-MX', { maximumFractionDigits: 2 });
    return n > 0 ? `+${formatted}` : formatted;
}

function qtyClass(v: string | number | null | undefined) {
    if (v == null || v === '') return 'text-muted-foreground';
    const n = Number(v);
    if (!Number.isFinite(n) || n === 0) return 'text-muted-foreground';
    return n > 0 ? 'text-emerald-700' : 'text-rose-700';
}

function fmtPlain(v: number | null | undefined) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toLocaleString('es-MX', { maximumFractionDigits: 2 });
}

function compactRefs(refs: Entry['external_references']) {
    if (!refs?.length) return [];
    const interesting = new Set(['shipment_id', 'inbound_id', 'order_id', 'pack_id']);
    const picked = refs.filter((r) => interesting.has(String(r.type ?? '')) && (r.value || r.id));
    const source = picked.length ? picked : refs.slice(0, 2);
    return source
        .map((r) => {
            const type = r.type ?? 'ref';
            const value = String(r.value ?? r.id ?? '');
            return value ? { type, value } : null;
        })
        .filter((x): x is { type: string; value: string } => Boolean(x));
}

const opsDelta = computed(() =>
    pctDelta(todaySummary.value.operations_today, todaySummary.value.operations_yesterday),
);
const netDelta = computed(() =>
    pctDelta(todaySummary.value.net_available_today, todaySummary.value.net_available_yesterday),
);
const inboundDelta = computed(() =>
    pctDelta(todaySummary.value.inbound_qty_today, todaySummary.value.inbound_qty_yesterday),
);
const saleDelta = computed(() =>
    pctDelta(todaySummary.value.sale_qty_today, todaySummary.value.sale_qty_yesterday),
);
</script>

<template>
    <Head title="Movimientos Full" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <PageHeader
                    compact
                    title="Movimientos Full"
                    description="Entradas, salidas y demás operaciones de stock en depósitos de Mercado Libre (solo lectura)."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="full_operations"
                            :get-payload="() => buildExportSelectionPayload({
                                selectedIds,
                                allPagesSelected,
                                totalCount,
                            })"
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="syncNow"
                        >
                            Sincronizar
                        </Button>
                        <Button
                            as="a"
                            size="sm"
                            variant="outline"
                            :href="route('stock.index')"
                        >
                            Volver a Stock
                        </Button>
                    </template>
                </PageHeader>

                <p
                    v-if="flashSuccess"
                    class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                >
                    {{ flashSuccess }}
                </p>

                <div class="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Movimientos hoy
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ todaySummary.operations_today.toLocaleString('es-MX') }}
                        </p>
                        <p class="mt-0.5 text-[10px]" :class="deltaClass(opsDelta)">
                            {{ formatPctDelta(opsDelta) }}
                        </p>
                        <button
                            type="button"
                            class="mt-2 inline-block text-[11px] font-semibold text-brand hover:underline"
                            @click="filterToday"
                        >
                            Ver hoy →
                        </button>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Δ disponible neto hoy
                        </p>
                        <p
                            class="mt-1 text-2xl font-semibold tracking-tight"
                            :class="qtyClass(todaySummary.net_available_today)"
                        >
                            {{ fmtDelta(todaySummary.net_available_today) }}
                        </p>
                        <p class="mt-0.5 text-[10px]" :class="deltaClass(netDelta)">
                            {{ formatPctDelta(netDelta) }}
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Ingresos Full hoy
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-emerald-700">
                            {{ fmtDelta(todaySummary.inbound_qty_today) }}
                        </p>
                        <p class="mt-0.5 text-[10px]" :class="deltaClass(inboundDelta)">
                            {{ formatPctDelta(inboundDelta) }}
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Salidas por venta hoy
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ fmtPlain(todaySummary.sale_qty_today) }}
                        </p>
                        <p class="mt-0.5 text-[10px]" :class="deltaClass(saleDelta)">
                            {{ formatPctDelta(saleDelta) }}
                        </p>
                    </Card>
                </div>

                <div
                    class="mb-3 inline-flex max-w-full flex-wrap gap-0.5 rounded-full bg-slate-100/90 p-0.5"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="inline-flex h-7 items-center gap-1 rounded-full px-2.5 text-[12px] font-medium tracking-tight transition"
                        :class="
                            (filters.tab || 'all') === tab.key
                                ? 'bg-white text-slate-900 shadow-[0_1px_2px_rgba(15,23,42,0.08)]'
                                : 'text-slate-500 hover:text-slate-800'
                        "
                        @click="selectTab(tab.key)"
                    >
                        {{ tab.label }}
                        <span
                            class="tabular-nums text-[11px]"
                            :class="
                                (filters.tab || 'all') === tab.key
                                    ? 'text-slate-500'
                                    : 'text-slate-400'
                            "
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>

                <FullOperationsSearchAndFilters
                    :filters="filters"
                    :connections="connections"
                    :operation-types="operation_types"
                    :operation-type-labels="operation_type_labels"
                    :before-apply="beforeApplyFilters"
                />

                <RecordSelectionBar
                    :has-selection="hasSelection"
                    :bulk-selection-props="bulkSelectionProps"
                    @select-all-filtered="selectAllInFilteredUniverse"
                    @clear-selection="clearSelection"
                    @header-toggle="onHeaderCheckboxChange"
                />
                <p
                    v-if="selectionNotice"
                    class="mb-2 text-xs"
                    :class="
                        selectionNotice.kind === 'error'
                            ? 'text-red-600'
                            : selectionNotice.kind === 'warning'
                              ? 'text-amber-700'
                              : 'text-emerald-700'
                    "
                >
                    {{ selectionNotice.text }}
                </p>

                <DataTable
                    compact
                    sticky-head
                    :is-empty="rows.length === 0"
                    empty-title="Sin operaciones Full"
                    empty-description="Sincroniza para traer ingresos, ventas, devoluciones y demás movimientos del depósito Full."
                >
                    <template #empty>
                        <Button
                            type="button"
                            size="sm"
                            @click="syncNow"
                        >
                            Sincronizar
                        </Button>
                    </template>
                    <template #head>
                        <TableHead class="w-10">
                            <TableSelectionCheckbox
                                :checked="allLoadedSelected"
                                :indeterminate="someLoadedSelected"
                                aria-label="Seleccionar movimientos cargados"
                                @change="onHeaderCheckboxChange"
                            />
                        </TableHead>
                        <TableHead>Fecha</TableHead>
                        <TableHead>Tipo</TableHead>
                        <TableHead>Canal</TableHead>
                        <TableHead>SKU / inventario</TableHead>
                        <TableHead>Δ disponible</TableHead>
                        <TableHead>Δ no disp.</TableHead>
                        <TableHead>Resultado</TableHead>
                        <TableHead>Refs</TableHead>
                    </template>
                    <TableRow
                        v-for="row in rows"
                        :key="row.id"
                        class="conn-row cursor-pointer"
                        :style="connectionSurfaceStyle(row.connection?.color)"
                        @click="openDetail(row)"
                    >
                        <TableCell
                            class="w-10"
                            @click.stop
                        >
                            <TableSelectionCheckbox
                                :checked="isSelected(row.id)"
                                aria-label="Seleccionar movimiento"
                                @change="toggle(row.id)"
                            />
                        </TableCell>
                        <TableCell>
                            <template v-if="row.occurred_at">
                                <div
                                    class="text-[12px] font-medium tracking-tight text-slate-800"
                                    :title="formatDateTime(row.occurred_at)"
                                >
                                    {{ formatRelativeShort(row.occurred_at) }}
                                </div>
                                <div class="text-[10px] text-muted-foreground">
                                    {{ formatDateTime(row.occurred_at) }}
                                </div>
                            </template>
                            <span v-else class="text-[12px] text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            <FullOperationTypePill
                                compact
                                :label="row.operation_type_label"
                                :variant="row.pill_variant || 'secondary'"
                                :code="row.operation_type"
                            />
                        </TableCell>
                        <TableCell>
                            <ConnectionChip
                                v-if="row.connection"
                                :connection="row.connection"
                            />
                            <span
                                v-else
                                class="text-[12px] text-muted-foreground"
                            >—</span>
                        </TableCell>
                        <TableCell class="max-w-[16rem]">
                            <button
                                v-if="row.variant?.sku || row.variant?.name"
                                type="button"
                                class="text-left"
                                @click.stop="selectedVariantId = row.variant?.id ?? null"
                            >
                                <StackedData
                                    :primary="row.variant?.sku || row.variant?.name"
                                    :secondary="row.inventory_id"
                                    primary-kind="code"
                                    secondary-kind="code"
                                />
                            </button>
                            <div
                                v-else
                                class="font-mono text-[11px] text-muted-foreground"
                            >
                                {{ row.inventory_id ?? '—' }}
                            </div>
                        </TableCell>
                        <TableCell
                            class="font-mono text-xs tabular-nums"
                            :class="qtyClass(row.available_quantity_delta)"
                        >
                            {{ fmtDelta(row.available_quantity_delta) }}
                        </TableCell>
                        <TableCell
                            class="font-mono text-xs tabular-nums"
                            :class="qtyClass(row.not_available_quantity_delta)"
                        >
                            {{ fmtDelta(row.not_available_quantity_delta) }}
                        </TableCell>
                        <TableCell class="font-mono text-[11px] text-muted-foreground">
                            <span v-if="row.result_available != null || row.result_total != null">
                                {{ row.result_available ?? '—' }} disp.
                                <span v-if="row.result_not_available">
                                    / {{ row.result_not_available }} no
                                </span>
                                <span v-if="row.result_total">
                                    (total {{ row.result_total }})
                                </span>
                            </span>
                            <span v-else>—</span>
                        </TableCell>
                        <TableCell>
                            <div
                                v-if="compactRefs(row.external_references).length"
                                class="flex max-w-[12rem] flex-wrap gap-1"
                            >
                                <span
                                    v-for="ref in compactRefs(row.external_references)"
                                    :key="`${ref.type}-${ref.value}`"
                                    class="inline-flex max-w-full truncate rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-600"
                                    :title="`${ref.type}:${ref.value}`"
                                >
                                    {{ ref.type.replace(/_id$/, '') }}:{{ ref.value }}
                                </span>
                            </div>
                            <span
                                v-else
                                class="text-[11px] text-muted-foreground"
                            >—</span>
                        </TableCell>
                    </TableRow>
                </DataTable>

                <div
                    ref="loadMoreSentinel"
                    class="h-1"
                    aria-hidden="true"
                />

                <div
                    v-if="isLoadingMore"
                    class="flex w-full items-center justify-center gap-2 py-3 text-xs text-slate-500"
                >
                    <span
                        class="inline-block size-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-brand"
                        aria-hidden="true"
                    />
                    Cargando más…
                </div>

                <InfiniteListSummary
                    compact
                    :total="totalCount"
                    :showing="showingCount"
                    :has-more="hasMorePages"
                    :from="paginationFrom"
                    :to="paginationTo"
                    singular-label="movimiento"
                    plural-label="movimientos"
                />
            </div>
        </div>

        <FullOperationDetailSlideOver
            :show="detailOpen"
            :operation-id="selectedOperationId"
            :connection="selectedConnection"
            @close="closeDetail"
        />

        <StockDetailSlideOver
            :show="selectedVariantId != null"
            :variant-id="selectedVariantId ?? undefined"
            @close="selectedVariantId = null"
        />
    </AuthenticatedLayout>
</template>
