<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { buildExportSelectionPayload } from '@/composables/buildExportSelectionPayload';
import DataTable from '@/Components/App/DataTable.vue';
import FilterBar from '@/Components/App/FilterBar.vue';
import InfiniteListSummary from '@/Components/App/InfiniteListSummary.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import StackedData from '@/Components/App/StackedData.vue';
import OrderStatusPill from '@/Components/Domain/OrderStatusPill.vue';
import OverdueReleaseCoverageBar from '@/Components/Domain/OverdueReleaseCoverageBar.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';
import Input from '@/Components/ui/Input.vue';
import Button from '@/Components/ui/Button.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import { connectionFilterLabel } from '@/lib/connectionLabel';
import { connectionSurfaceStyle, resolveConnectionColor } from '@/lib/connectionColor';
import { parseBuyerSummaryFallback, toTitleCase } from '@/lib/formatDisplayText';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import TableSelectionCheckbox from '@/Components/ui/TableSelectionCheckbox.vue';
import RecordSelectionBar from '@/Components/ui/ListToolbar/RecordSelectionBar.vue';
import SlideOverShell from '@/Components/ui/SlideOverShell.vue';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import {
    ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS,
    SLIDE_OVER_TABS_LIST_CLASS,
    SLIDE_OVER_TABS_TRIGGER_CLASS,
} from '@/lib/slideOverLayout';
import { Head, router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { useInfiniteList } from '@/composables/useInfiniteList';
import { useRecordSelection } from '@/composables/useRecordSelection';
import { formatSelectionNumeric } from '@/lib/selectionNumeric';
import {
    buildOrdersListUpdatesUrl,
    getOrdersListScrollEl,
    scrollOrdersListToTop,
    useOrdersListLiveUpdates,
} from '@/composables/useOrdersListLiveUpdates';
import { formatDateTime, formatRelativeShort } from '@/lib/utils';
import { AlertTriangle, ClipboardList, Link2, Megaphone, MessageCircle, Package, Receipt, RefreshCw, ShieldAlert } from 'lucide-vue-next';

const OrderDetailPanel = defineAsyncComponent(
    () => import('@/Components/Orders/OrderDetailPanel.vue'),
);
const ShipmentSummarySlideOver = defineAsyncComponent(
    () => import('@/Components/Fulfillment/ShipmentSummarySlideOver.vue'),
);
const OrderStockReservationsSlideOver = defineAsyncComponent(
    () => import('@/Components/Orders/OrderStockReservationsSlideOver.vue'),
);
const OrderTimelineStepSlideOver = defineAsyncComponent(
    () => import('@/Components/Orders/OrderTimelineStepSlideOver.vue'),
);
const OrderBuyerSlideOver = defineAsyncComponent(
    () => import('@/Components/Orders/OrderBuyerSlideOver.vue'),
);
const ProductDetailSlideOver = defineAsyncComponent(
    () => import('@/Components/Products/ProductDetailSlideOver.vue'),
);

interface OrderRow {
    id: number;
    external_order_id: string | null;
    status: string;
    release_issue?: 'orphan' | 'unreleased' | 'held' | string | null;
    release_delivered_at?: string | null;
    expected_release_at?: string | null;
    expected_release_source?: 'ml' | 'estimated' | string | null;
    post_sale_outcome?: string | null;
    currency_code: string;
    total_amount: string | number;
    profit_revenue?: string | number;
    profit_fees?: string | number;
    profit_taxes?: string | number;
    profit_marketplace_net?: string | number;
    profit_cogs?: string | number;
    profit_amount?: string | number;
    profit_incomplete?: boolean;
    ordered_at: string | null;
    lines_count?: number;
    product_summary?: {
        title?: string | null;
        sku?: string | null;
        product_id?: number | null;
        ml_item_id?: string | null;
        more_count?: number;
        units_sold?: number | null;
    } | null;
    inbound_messages_count?: number;
    has_ads_attribution?: boolean;
    ads_allocated_amount?: number;
    platform?: string | null;
    pack_external_id?: string | null;
    pack_orders_count?: number;
    pack_group_kind?: 'pack' | 'burst' | null;
    buyer_summary?: string | null;
    buyer_display?: {
        primary: string;
        secondary?: string | null;
        primary_kind: string;
        secondary_kind?: string | null;
    } | null;
    connection?: {
        id: number;
        provider: string;
        external_user_id?: string | null;
        display_name?: string | null;
        color?: string | null;
    } | null;
}

type PackRailPosition = 'start' | 'middle' | 'end' | 'single' | null;

function packKey(order: OrderRow): string | null {
    const key = order.pack_external_id;
    if (!key || (order.pack_orders_count ?? 1) <= 1) return null;
    return `${order.connection?.id ?? 'x'}:${key}`;
}

function packRailPosition(order: OrderRow, index: number, items: OrderRow[]): PackRailPosition {
    const key = packKey(order);
    if (!key) return null;
    const prev = items[index - 1];
    const next = items[index + 1];
    const prevSame = Boolean(prev && packKey(prev) === key);
    const nextSame = Boolean(next && packKey(next) === key);
    if (!prevSame && nextSame) return 'start';
    if (prevSame && nextSame) return 'middle';
    if (prevSame && !nextSame) return 'end';
    return 'single';
}

function packLinkTitle(order: OrderRow): string {
    const n = order.pack_orders_count ?? 1;
    if (order.pack_group_kind === 'pack') {
        return `Misma compra (pack ML) repartida en ${n} órdenes`;
    }
    return `Mismo comprador en la misma ventana de compra · ${n} órdenes`;
}

function packUi(order: OrderRow, index: number, items: OrderRow[]) {
    const position = packRailPosition(order, index, items);
    if (!position) return null;
    return {
        position,
        title: packLinkTitle(order),
        count: order.pack_orders_count ?? 1,
        showLineDown: position === 'start' || position === 'middle',
        showLineUp: position === 'middle' || position === 'end',
    };
}

function buyerDisplayFor(order: OrderRow) {
    if (order.buyer_display?.primary) {
        return order.buyer_display;
    }
    return parseBuyerSummaryFallback(order.buyer_summary);
}

function buyerStackedProps(order: OrderRow) {
    const display = buyerDisplayFor(order);
    if (!display) return null;
    return {
        primary: display.primary,
        secondary: display.secondary ?? null,
        primaryKind: display.primary_kind,
        secondaryKind: display.secondary_kind ?? null,
    };
}

interface PaginatedOrders {
    data: OrderRow[];
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
    from?: number | null;
    to?: number | null;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}

interface DetailMeta {
    hasShipment?: boolean;
    shipmentId?: number | null;
    status?: string | null;
    externalOrderId?: string | number | null;
    canSync?: boolean;
    channelLabel?: string | null;
    connectionId?: number | null;
    connectionLabel?: string | null;
    connectionColor?: string | null;
    buyerInboundCount?: number;
    hasClaims?: boolean;
    claimsOpenedCount?: number;
    claimsTotalCount?: number;
}

const props = defineProps<{
    orders: PaginatedOrders;
    filters: {
        q: string;
        status: string;
        connection_id: number | null;
        from: string;
        to: string;
        tab: string;
        release_issue?: string | null;
    };
    status_counts: Record<string, number>;
    overdue_release_count?: number;
    release_coverage?: Record<string, unknown> | null;
    connections: Array<{
        id: number;
        provider: string;
        external_user_id: string | null;
        display_name: string | null;
        color?: string | null;
    }>;
    today_sales: {
        orders_today: number;
        orders_yesterday: number;
        revenue_today: number;
        revenue_yesterday: number;
        expected_profit_today: number;
        incomplete_orders_today: number;
        avg_ticket_today: number;
        currency: string;
        date: string;
    };
}>();

const q = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? '');
const connectionId = ref(props.filters.connection_id ? String(props.filters.connection_id) : '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const releaseIssue = ref(props.filters.release_issue ?? '');

const detailOpen = ref(false);
const selectedOrderId = ref<number | null>(null);
const detailTab = ref('details');
const detailMeta = ref<DetailMeta | null>(null);
const orderDetailPanelRef = ref<{ syncNow?: () => Promise<void> } | null>(null);
const headerSyncing = ref(false);

const shipmentOpen = ref(false);
const selectedShipmentId = ref<number | null>(null);

const stockOpen = ref(false);
const stockOrderId = ref<number | null>(null);

const stepOpen = ref(false);
const stepKey = ref<string | null>(null);
const stepOrder = ref<Record<string, unknown> | null>(null);

const buyerOpen = ref(false);
const buyerOrder = ref<Record<string, unknown> | null>(null);

const productSlideOpen = ref(false);
const selectedProductId = ref<number | null>(null);
const selectedMlItemId = ref<string | null>(null);
const productSlideInitialTab = ref<'publication' | 'product'>('publication');

const tabs = computed(() => [
    { key: 'all', label: 'Todas', count: Object.values(props.status_counts).reduce((a, b) => a + Number(b), 0) },
    { key: 'paid', label: 'Pagada', count: Number(props.status_counts.paid ?? 0) },
    { key: 'pending', label: 'Pendiente', count: Number(props.status_counts.pending ?? 0) },
    { key: 'shipped', label: 'En tránsito', count: Number(props.status_counts.shipped ?? 0) },
    { key: 'delivered', label: 'Entregada', count: Number(props.status_counts.delivered ?? 0) },
    { key: 'cancelled', label: 'Cancelada', count: Number(props.status_counts.cancelled ?? 0) },
]);

const {
    displayItems: baseDisplayItems,
    hasMorePages,
    totalCount,
    paginationFrom,
    paginationTo,
    isLoading,
    isLoadingMore,
    lastLoadedPage,
    loadMoreSentinel,
    resetAccumulation,
} = useInfiniteList({
    initialPaginator: computed(() => props.orders),
});

const livePrependedItems = ref<OrderRow[]>([]);
const pendingNewItems = ref<OrderRow[]>([]);
const pendingNewCount = ref(0);
const liveTotalBoost = ref(0);
const sinceId = ref(0);
const liveNewItemIds = ref<Record<number, boolean>>({});

function getBaseDisplayItems(): OrderRow[] {
    return (baseDisplayItems.value as OrderRow[]) ?? [];
}

/** Newest order date first; nulls last; tie-break by id desc. */
function sortOrdersNewestFirst(rows: OrderRow[]): OrderRow[] {
    return [...rows].sort((a, b) => {
        const aMs = a.ordered_at ? Date.parse(a.ordered_at) : Number.NaN;
        const bMs = b.ordered_at ? Date.parse(b.ordered_at) : Number.NaN;
        const aValid = Number.isFinite(aMs);
        const bValid = Number.isFinite(bMs);
        if (aValid && bValid && aMs !== bMs) return bMs - aMs;
        if (aValid !== bValid) return aValid ? -1 : 1;
        return parseInt(String(b.id), 10) - parseInt(String(a.id), 10);
    });
}

const ordersDisplayItems = computed(() => {
    const base = getBaseDisplayItems();
    const baseIds = new Set(base.map((s) => parseInt(String(s.id), 10)));
    const prepended = livePrependedItems.value.filter(
        (s) => !baseIds.has(parseInt(String(s.id), 10)),
    );
    return sortOrdersNewestFirst([...prepended, ...base]);
});

const orderRows = computed(() => {
    const items = ordersDisplayItems.value;
    return items.map((order, index) => ({
        order,
        pack: packUi(order, index, items),
    }));
});

const totalShown = computed(() => Number(totalCount.value || 0) + Number(liveTotalBoost.value || 0));

const showingCount = computed(() => ordersDisplayItems.value.length);

const orderSummableColumns = computed(() => {
    const currency = props.today_sales?.currency || 'MXN';
    const money = { style: 'currency' as const, currency };
    return [
        { key: 'total_amount', label: 'Total', fieldMeta: money },
        { key: 'profit_revenue', label: 'Ingresos', fieldMeta: money },
        { key: 'profit_fees', label: 'Fees', fieldMeta: money },
        { key: 'profit_taxes', label: 'Retenciones', fieldMeta: money },
        { key: 'profit_marketplace_net', label: 'Neto ML', fieldMeta: money },
        { key: 'profit_cogs', label: 'COGS', fieldMeta: money },
        { key: 'profit_amount', label: 'Utilidad', fieldMeta: money },
    ];
});

const {
    selectedIds,
    allPagesSelected,
    hasSelection,
    bulkSelectionProps,
    allLoadedSelected,
    someLoadedSelected,
    selectionNotice,
    isSelected,
    toggle: toggleOrderSelection,
    onHeaderCheckboxChange,
    selectAllInFilteredUniverse,
    clearSelection,
} = useRecordSelection({
    loadedRows: ordersDisplayItems,
    totalCount: totalShown,
    summableColumns: orderSummableColumns,
    currentQuery: () => ({
        q: props.filters.q || undefined,
        status: props.filters.status || undefined,
        connection_id: props.filters.connection_id || undefined,
        from: props.filters.from || undefined,
        to: props.filters.to || undefined,
        tab: props.filters.tab || undefined,
        release_issue: props.filters.release_issue || undefined,
    }),
    endpoints: {
        allIds: () => route('orders.all-ids'),
        filteredSums: () => route('orders.filtered-sums'),
    },
    formatNumeric: (n, meta) =>
        formatSelectionNumeric(n, (meta ?? {}) as {
            currency?: string | null;
            style?: 'currency' | 'decimal' | 'integer';
        }),
    itemLabel: 'órdenes',
});

const hasLoadedBeyondFirstPage = computed(() => {
    const loaded = lastLoadedPage.value ?? props.orders?.current_page ?? 1;
    return Number(loaded) > 1;
});

const liveUpdatesEnabled = computed(() => true);

function recomputeSinceId() {
    let max = 0;
    for (const s of [...livePrependedItems.value, ...getBaseDisplayItems()]) {
        const id = parseInt(String(s.id), 10);
        if (Number.isFinite(id) && id > max) max = id;
    }
    sinceId.value = max;
}

function bumpSinceIdFromItems(items: OrderRow[]) {
    let max = sinceId.value;
    for (const s of items) {
        const id = parseInt(String(s.id), 10);
        if (Number.isFinite(id) && id > max) max = id;
    }
    sinceId.value = max;
}

function filterFreshItems(items: OrderRow[]) {
    const existingIds = new Set(
        [
            ...getBaseDisplayItems(),
            ...livePrependedItems.value,
            ...pendingNewItems.value,
        ].map((s) => parseInt(String(s.id), 10)),
    );
    return items.filter((s) => !existingIds.has(parseInt(String(s.id), 10)));
}

function markLiveNewItems(items: OrderRow[]) {
    if (!items?.length) return;
    const next = { ...liveNewItemIds.value };
    for (const s of items) next[s.id] = true;
    liveNewItemIds.value = next;
    const ids = items.map((s) => s.id);
    setTimeout(() => {
        const cleaned = { ...liveNewItemIds.value };
        for (const id of ids) delete cleaned[id];
        liveNewItemIds.value = cleaned;
    }, 4000);
}

function handleLivePrepend(items: OrderRow[]) {
    const fresh = filterFreshItems(items);
    if (!fresh.length) return;
    livePrependedItems.value = [...fresh, ...livePrependedItems.value];
    liveTotalBoost.value += fresh.length;
    markLiveNewItems(fresh);
    bumpSinceIdFromItems(fresh);
}

function handleLivePending(items: OrderRow[]) {
    const fresh = filterFreshItems(items);
    if (!fresh.length) return;
    pendingNewItems.value = [...fresh, ...pendingNewItems.value];
    pendingNewCount.value = pendingNewItems.value.length;
    bumpSinceIdFromItems(fresh);
}

function mergePendingLiveUpdates() {
    if (!pendingNewItems.value.length) return;
    const pending = pendingNewItems.value;
    livePrependedItems.value = [...pending, ...livePrependedItems.value];
    liveTotalBoost.value += pending.length;
    markLiveNewItems(pending);
    pendingNewItems.value = [];
    pendingNewCount.value = 0;
    scrollOrdersListToTop(getOrdersListScrollEl(loadMoreSentinel.value));
}

function clearLiveUpdates() {
    livePrependedItems.value = [];
    pendingNewItems.value = [];
    pendingNewCount.value = 0;
    liveTotalBoost.value = 0;
    liveNewItemIds.value = {};
    recomputeSinceId();
}

watch(
    () => [props.orders, baseDisplayItems.value],
    () => recomputeSinceId(),
    { deep: true, immediate: true },
);

watch(
    () => ({
        ids: (props.orders?.data ?? []).map((o) => o.id).join(','),
        filters: props.filters,
    }),
    (next, prev) => {
        if (!prev) return;
        if (next.ids !== prev.ids || JSON.stringify(next.filters) !== JSON.stringify(prev.filters)) {
            clearLiveUpdates();
        }
    },
);

useOrdersListLiveUpdates({
    enabled: liveUpdatesEnabled,
    sinceId,
    isLoading,
    isLoadingMore,
    hasLoadedBeyondFirstPage,
    getScrollEl: () => getOrdersListScrollEl(loadMoreSentinel.value),
    buildUpdatesUrl: buildOrdersListUpdatesUrl,
    onPrepend: handleLivePrepend,
    onPending: handleLivePending,
});

function applyFilters(extra: Record<string, string | number | null> = {}) {
    clearLiveUpdates();
    resetAccumulation();
    router.get(
        route('orders.index'),
        {
            q: q.value || undefined,
            status: status.value || undefined,
            connection_id: connectionId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            release_issue: releaseIssue.value || undefined,
            page: 1,
            ...extra,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

function selectTab(tab: string) {
    status.value = tab === 'all' ? '' : tab;
    releaseIssue.value = '';
    applyFilters({ tab, release_issue: undefined });
}

function filterOverdueReleases(kind: string = 'overdue', clearDates = false) {
    releaseIssue.value = kind;
    status.value = '';
    if (clearDates) {
        from.value = '';
        to.value = '';
    }
    applyFilters({
        tab: 'all',
        status: undefined,
        release_issue: kind,
        ...(clearDates ? { from: undefined, to: undefined } : {}),
    });
}

function releaseIssueLabel(kind: string | null | undefined): string {
    if (kind === 'orphan') return 'Huérfana';
    if (kind === 'unreleased') return 'Sin liberar';
    if (kind === 'held') return 'En reserva';
    if (kind === 'overdue') return 'Liberación atrasada';
    return kind || '';
}

function releaseIssueVariant(kind: string | null | undefined): 'danger' | 'warning' | 'outline' {
    if (kind === 'orphan' || kind === 'unreleased') return 'danger';
    if (kind === 'held') return 'warning';
    return 'outline';
}

function expectedReleaseSourceLabel(source: string | null | undefined): string {
    if (source === 'ml') return 'ML';
    if (source === 'estimated') return 'entrega + 2d';
    return '';
}

function onReleaseCoverageSynced() {
    router.reload({ only: ['orders', 'overdue_release_count', 'release_coverage', 'status_counts'] });
}

function filterToday() {
    const date = props.today_sales.date;
    from.value = date;
    to.value = date;
    applyFilters({ from: date, to: date });
}

function pctDelta(current: number, previous: number): number | null {
    if (previous === 0) {
        return current === 0 ? 0 : null;
    }
    return ((current - previous) / previous) * 100;
}

function formatDelta(delta: number | null): string {
    if (delta == null) return 'vs ayer';
    const sign = delta > 0 ? '+' : '';
    return `${sign}${delta.toFixed(1)}% vs ayer`;
}

function deltaClass(delta: number | null) {
    if (delta == null) return 'text-muted-foreground';
    if (delta > 0) return 'text-emerald-700';
    if (delta < 0) return 'text-red-600';
    return 'text-muted-foreground';
}

const ordersTodayDelta = computed(() =>
    pctDelta(props.today_sales.orders_today, props.today_sales.orders_yesterday),
);
const revenueTodayDelta = computed(() =>
    pctDelta(props.today_sales.revenue_today, props.today_sales.revenue_yesterday),
);

function openDetail(id: number, tab: string = 'details') {
    selectedOrderId.value = id;
    detailTab.value = tab;
    detailMeta.value = null;
    headerSyncing.value = false;
    detailOpen.value = true;
}

function openMessages(id: number) {
    openDetail(id, 'messages');
}

function openAds(id: number) {
    openDetail(id, 'ads');
}

function closeDetail() {
    detailOpen.value = false;
    selectedOrderId.value = null;
    detailTab.value = 'details';
    detailMeta.value = null;
    headerSyncing.value = false;
    closeShipment();
    closeStock();
    closeStep();
    closeBuyer();
    closeProductSlide();
}

const selectedOrderRow = computed(() => {
    const id = selectedOrderId.value;
    if (id == null) return null;
    return ordersDisplayItems.value.find((o) => o.id === id) ?? null;
});

const detailOrderNumber = computed(() => {
    const fromMeta = detailMeta.value?.externalOrderId;
    if (fromMeta != null && fromMeta !== '') return fromMeta;
    return selectedOrderRow.value?.external_order_id ?? selectedOrderId.value;
});

const detailConnection = computed(() => {
    const connectionId =
        detailMeta.value?.connectionId ?? selectedOrderRow.value?.connection?.id ?? null;
    if (connectionId != null) {
        const fromFilters = props.connections.find((c) => c.id === connectionId);
        if (fromFilters) return fromFilters;
    }
    return selectedOrderRow.value?.connection ?? null;
});

const detailConnectionLabel = computed(() => {
    if (detailMeta.value?.connectionLabel) return detailMeta.value.connectionLabel;
    if (detailConnection.value) return connectionFilterLabel(detailConnection.value);
    const provider = selectedOrderRow.value?.connection?.provider;
    if (provider === 'mercadolibre') return 'Mercado Libre';
    return provider ?? null;
});

const detailConnectionForChip = computed(() => {
    const base = detailConnection.value;
    if (!base && !detailConnectionLabel.value) return null;
    return {
        ...(base ?? {}),
        id: base?.id ?? detailMeta.value?.connectionId ?? 0,
        provider: base?.provider ?? 'mercadolibre',
        display_name:
            base?.display_name ??
            detailMeta.value?.connectionLabel ??
            detailConnectionLabel.value,
        color:
            detailMeta.value?.connectionColor ??
            base?.color ??
            null,
    };
});

const canSyncSelectedOrder = computed(() => {
    if (detailMeta.value?.canSync != null) return detailMeta.value.canSync;
    return selectedOrderRow.value?.connection?.provider === 'mercadolibre';
});

async function syncSelectedOrder() {
    const panel = orderDetailPanelRef.value;
    if (!panel?.syncNow || headerSyncing.value) return;
    headerSyncing.value = true;
    try {
        await panel.syncNow();
    } finally {
        headerSyncing.value = false;
    }
}

function openShipment(shipmentId: number) {
    selectedShipmentId.value = shipmentId;
    shipmentOpen.value = true;
}

function closeShipment() {
    shipmentOpen.value = false;
    selectedShipmentId.value = null;
}

function openStock(orderId: number) {
    stockOrderId.value = orderId;
    stockOpen.value = true;
}

function closeStock() {
    stockOpen.value = false;
    stockOrderId.value = null;
}

function openStep(payload: { key: string; order?: Record<string, unknown> | null }) {
    stepKey.value = payload.key;
    stepOrder.value = payload.order ?? null;
    stepOpen.value = true;
}

function closeStep() {
    stepOpen.value = false;
    stepKey.value = null;
    stepOrder.value = null;
}

function openBuyer(order: Record<string, unknown> | null) {
    buyerOrder.value = order;
    buyerOpen.value = true;
}

function closeBuyer() {
    buyerOpen.value = false;
    buyerOrder.value = null;
}

function onBuyerOpenOrder(orderId: number) {
    closeBuyer();
    openDetail(orderId);
}

function onTimelineStep(payload: {
    key: string;
    orderId?: number;
    order?: Record<string, unknown> | null;
}) {
    if (payload.key === 'reserved') {
        openStock(payload.orderId ?? selectedOrderId.value!);
        return;
    }
    if (payload.key === 'ordered' || payload.key === 'paid') {
        openStep({ key: payload.key, order: payload.order ?? { id: payload.orderId } });
        return;
    }
    if ((payload.key === 'shipped' || payload.key === 'delivered') && detailMeta.value?.shipmentId) {
        openShipment(detailMeta.value.shipmentId);
    }
}

function onShipmentOpenOrder(orderId: number) {
    closeShipment();
    openDetail(orderId);
}

function productLabel(summary: OrderRow['product_summary']): string {
    if (!summary) return '—';
    let label = '';
    if (summary.title) label = toTitleCase(summary.title);
    else if (summary.sku) label = summary.sku;
    else if (summary.ml_item_id) label = summary.ml_item_id;
    else label = 'Producto';

    const units = summary.units_sold;
    if (units != null && Number(units) > 0) {
        return `${label} · ${Number(units)}`;
    }
    return label;
}

function canOpenProduct(summary: OrderRow['product_summary']): boolean {
    return Boolean(summary?.product_id || summary?.ml_item_id);
}

function openProductFromSummary(summary: OrderRow['product_summary']) {
    if (!summary || !canOpenProduct(summary)) return;
    selectedProductId.value = summary.product_id ?? null;
    selectedMlItemId.value = summary.ml_item_id ?? null;
    productSlideInitialTab.value = summary.ml_item_id ? 'publication' : 'product';
    productSlideOpen.value = true;
}

function openProductFromLine(payload: { productId?: number | null; mlItemId?: string | null }) {
    const productId = payload.productId ?? null;
    const mlItemId = payload.mlItemId ?? null;
    if (productId == null && !mlItemId) return;
    selectedProductId.value = productId;
    selectedMlItemId.value = mlItemId;
    productSlideInitialTab.value = mlItemId ? 'publication' : 'product';
    productSlideOpen.value = true;
}

function closeProductSlide() {
    productSlideOpen.value = false;
    selectedProductId.value = null;
    selectedMlItemId.value = null;
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(q, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 300);
});
</script>

<template>
    <Head title="Órdenes" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <PageHeader
                    compact
                    title="Órdenes"
                    description="Inbox unificado de ventas de todos tus canales."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="orders"
                            :get-payload="() => buildExportSelectionPayload({
                                selectedIds,
                                allPagesSelected,
                                totalCount: totalShown,
                            })"
                        />
                    </template>
                </PageHeader>

                <div class="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Órdenes hoy · exitosas
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ today_sales.orders_today }}
                        </p>
                        <p class="mt-0.5 text-[10px]" :class="deltaClass(ordersTodayDelta)">
                            {{ formatDelta(ordersTodayDelta) }}
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
                            Ingresos hoy
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText
                                :amount="today_sales.revenue_today"
                                :currency="today_sales.currency"
                            />
                        </p>
                        <p class="mt-0.5 text-[10px]" :class="deltaClass(revenueTodayDelta)">
                            {{ formatDelta(revenueTodayDelta) }}
                        </p>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Utilidad esperada hoy
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText
                                :amount="today_sales.expected_profit_today"
                                :currency="today_sales.currency"
                            />
                        </p>
                        <p class="mt-0.5 text-[10px] text-muted-foreground">
                            {{ today_sales.incomplete_orders_today }} órdenes incompletas
                        </p>
                    </Card>

                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Ticket promedio
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText
                                :amount="today_sales.avg_ticket_today"
                                :currency="today_sales.currency"
                            />
                        </p>
                        <p class="mt-0.5 text-[10px] text-muted-foreground">
                            Ingresos ÷ órdenes exitosas
                        </p>
                    </Card>
                </div>

                <OverdueReleaseCoverageBar
                    v-if="(overdue_release_count ?? 0) > 0 || releaseIssue"
                    :coverage="release_coverage"
                    :connections="connections"
                    :connection-id="connectionId || null"
                    @synced="onReleaseCoverageSynced"
                />

                <div
                    v-if="(overdue_release_count ?? 0) > 0 || releaseIssue"
                    class="mb-3"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px] font-medium transition"
                        :class="
                            releaseIssue === 'overdue' || releaseIssue === 'orphan' || releaseIssue === 'unreleased'
                                ? 'border-rose-200 bg-rose-50 text-rose-800'
                                : 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100'
                        "
                        @click="filterOverdueReleases('overdue', true)"
                    >
                        <AlertTriangle class="size-3.5" />
                        {{ overdue_release_count ?? 0 }}
                        {{ (overdue_release_count ?? 0) === 1 ? 'liberación atrasada' : 'liberaciones atrasadas' }}
                    </button>
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

                <FilterBar compact>
                    <Input
                        v-model="q"
                        placeholder="Buscar id, externo, comprador…"
                        class="h-8 max-w-md flex-1 px-2.5 text-xs shadow-none"
                    />
                    <select
                        v-model="connectionId"
                        class="h-8 rounded-md border border-input bg-white px-2.5 text-xs"
                        :style="
                            connectionId
                                ? {
                                      borderColor: resolveConnectionColor(
                                          connections.find(
                                              (c) => String(c.id) === connectionId,
                                          )?.color,
                                      ),
                                      boxShadow: `inset 3px 0 0 ${resolveConnectionColor(
                                          connections.find(
                                              (c) => String(c.id) === connectionId,
                                          )?.color,
                                      )}`,
                                  }
                                : undefined
                        "
                        @change="applyFilters()"
                    >
                        <option value="">Todos los canales</option>
                        <option
                            v-for="c in connections"
                            :key="c.id"
                            :value="String(c.id)"
                        >
                            {{ connectionFilterLabel(c) }}
                        </option>
                    </select>
                    <Input
                        v-model="from"
                        type="date"
                        class="h-8 max-w-[9.5rem] px-2.5 text-xs shadow-none"
                        @change="applyFilters()"
                    />
                    <Input
                        v-model="to"
                        type="date"
                        class="h-8 max-w-[9.5rem] px-2.5 text-xs shadow-none"
                        @change="applyFilters()"
                    />
                    <select
                        v-model="releaseIssue"
                        class="h-8 rounded-md border border-input bg-white px-2.5 text-xs"
                        @change="releaseIssue ? filterOverdueReleases(releaseIssue) : applyFilters({ release_issue: undefined })"
                    >
                        <option value="">Liberación</option>
                        <option value="overdue">Atrasada</option>
                        <option value="orphan">Huérfana</option>
                        <option value="unreleased">Sin liberar</option>
                        <option value="held">En reserva</option>
                    </select>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="h-8 px-2.5 text-xs"
                        @click="applyFilters()"
                    >
                        Filtrar
                    </Button>
                </FilterBar>

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

                <div
                    v-if="pendingNewCount > 0"
                    class="sticky top-0 z-20 mb-2 flex justify-center px-2"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full border border-brand/25 bg-brand/[0.08] px-4 py-2 text-[12px] font-semibold text-brand shadow-sm transition hover:bg-brand/[0.12]"
                        @click="mergePendingLiveUpdates"
                    >
                        <span class="text-[10px]" aria-hidden="true">↑</span>
                        <span>
                            {{ pendingNewCount }}
                            {{ pendingNewCount === 1 ? 'orden nueva' : 'órdenes nuevas' }}
                            — Ver arriba
                        </span>
                    </button>
                </div>

                <DataTable
                    compact
                    :is-empty="ordersDisplayItems.length === 0"
                    empty-title="Sin órdenes"
                    empty-description="Las órdenes aparecen cuando un canal sincroniza ventas."
                >
                    <template #head>
                        <TableHead class="w-10">
                            <TableSelectionCheckbox
                                :checked="allLoadedSelected"
                                :indeterminate="someLoadedSelected"
                                aria-label="Seleccionar órdenes cargadas"
                                @change="onHeaderCheckboxChange"
                            />
                        </TableHead>
                        <TableHead>Orden</TableHead>
                        <TableHead>Canal</TableHead>
                        <TableHead>Comprador</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead v-if="releaseIssue">Liberación tent.</TableHead>
                        <TableHead>Total</TableHead>
                        <TableHead>Producto</TableHead>
                        <TableHead class="w-12 text-center">Ads</TableHead>
                        <TableHead class="w-12 text-center">Msgs</TableHead>
                        <TableHead>Fecha</TableHead>
                        <TableHead class="w-14 text-right">Pack</TableHead>
                    </template>

                    <TableRow
                        v-for="{ order, pack } in orderRows"
                        :key="order.id"
                        class="conn-row cursor-pointer"
                        :class="{ 'order-live-new': liveNewItemIds[order.id] }"
                        :style="connectionSurfaceStyle(order.connection?.color)"
                        @click="openDetail(order.id)"
                    >
                        <TableCell class="w-10" @click.stop>
                            <TableSelectionCheckbox
                                :checked="isSelected(order.id)"
                                aria-label="Seleccionar orden"
                                @change="toggleOrderSelection(order.id)"
                            />
                        </TableCell>
                        <TableCell>
                            <button
                                type="button"
                                class="text-[13px] font-medium tracking-tight text-brand hover:underline"
                                @click.stop="openDetail(order.id)"
                            >
                                #{{ order.external_order_id ?? order.id }}
                            </button>
                        </TableCell>
                        <TableCell>
                            <ConnectionChip
                                v-if="order.connection"
                                :connection="order.connection"
                            />
                            <span
                                v-else
                                class="inline-flex h-5 items-center rounded-full bg-slate-100 px-1.5 text-[10px] font-medium capitalize text-slate-600"
                            >
                                {{ order.platform ?? '—' }}
                            </span>
                        </TableCell>
                        <TableCell class="max-w-[18rem]">
                            <template v-if="buyerStackedProps(order)">
                                <StackedData v-bind="buyerStackedProps(order)!" />
                            </template>
                            <span v-else class="text-[12px] text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap items-center gap-1">
                                <OrderStatusPill
                                    compact
                                    :status="order.status"
                                    :outcome="order.post_sale_outcome"
                                />
                                <Badge
                                    v-if="order.release_issue"
                                    :variant="releaseIssueVariant(order.release_issue)"
                                    class="text-[10px]"
                                >
                                    {{ releaseIssueLabel(order.release_issue) }}
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell v-if="releaseIssue">
                            <template v-if="order.expected_release_at">
                                <div
                                    class="text-[12px] font-medium tracking-tight text-slate-800"
                                    :title="formatDateTime(order.expected_release_at)"
                                >
                                    {{ formatRelativeShort(order.expected_release_at) }}
                                </div>
                                <div class="text-[10px] text-muted-foreground">
                                    {{ expectedReleaseSourceLabel(order.expected_release_source) || formatDateTime(order.expected_release_at) }}
                                </div>
                            </template>
                            <span v-else class="text-[12px] text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell class="text-[12px] tabular-nums">
                            <MoneyText
                                :amount="order.total_amount"
                                :currency="order.currency_code"
                            />
                        </TableCell>
                        <TableCell class="max-w-[16rem]" @click.stop>
                            <template v-if="order.product_summary">
                                <button
                                    v-if="canOpenProduct(order.product_summary)"
                                    type="button"
                                    class="block max-w-full truncate text-left text-[12px] font-medium text-brand hover:underline"
                                    :title="productLabel(order.product_summary)"
                                    @click="openProductFromSummary(order.product_summary)"
                                >
                                    {{ productLabel(order.product_summary) }}
                                </button>
                                <span
                                    v-else
                                    class="block max-w-full truncate text-[12px] text-slate-700"
                                    :title="productLabel(order.product_summary)"
                                >
                                    {{ productLabel(order.product_summary) }}
                                </span>
                                <div
                                    v-if="(order.product_summary.more_count ?? 0) > 0"
                                    class="text-[10px] text-muted-foreground"
                                >
                                    +{{ order.product_summary.more_count }} más
                                </div>
                            </template>
                            <span v-else class="text-[12px] text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell class="text-center">
                            <button
                                v-if="order.has_ads_attribution"
                                type="button"
                                class="relative inline-flex size-7 items-center justify-center rounded-full text-teal-700 transition hover:bg-teal-50"
                                :title="
                                    order.ads_allocated_amount
                                        ? `Publicidad atribuida · $${Number(order.ads_allocated_amount).toFixed(0)}`
                                        : 'Llegó con gasto de publicidad atribuido'
                                "
                                @click.stop="openAds(order.id)"
                            >
                                <Megaphone class="size-3.5" />
                            </button>
                            <span v-else class="text-[10px] text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell class="text-center">
                            <button
                                v-if="(order.inbound_messages_count ?? 0) > 0"
                                type="button"
                                class="relative inline-flex size-7 items-center justify-center rounded-full text-brand transition hover:bg-brand/10"
                                title="Ver mensajes del comprador"
                                @click.stop="openMessages(order.id)"
                            >
                                <MessageCircle class="size-3.5" />
                                <span
                                    class="absolute -right-0.5 -top-0.5 inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-brand px-0.5 text-[9px] font-semibold leading-none text-white"
                                >
                                    {{ order.inbound_messages_count }}
                                </span>
                            </button>
                            <span v-else class="text-[10px] text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            <template v-if="order.ordered_at">
                                <div
                                    class="text-[12px] font-medium tracking-tight text-slate-800"
                                    :title="formatDateTime(order.ordered_at)"
                                >
                                    {{ formatRelativeShort(order.ordered_at) }}
                                </div>
                                <div class="text-[10px] text-muted-foreground">
                                    {{ formatDateTime(order.ordered_at) }}
                                </div>
                            </template>
                            <template v-else>—</template>
                        </TableCell>
                        <TableCell class="w-14">
                            <div
                                v-if="pack"
                                class="relative ml-auto flex h-9 w-5 items-center justify-center"
                                :title="pack.title"
                            >
                                <span
                                    v-if="pack.showLineDown"
                                    class="absolute left-1/2 top-[18px] h-[calc(50%+0.5rem)] w-0.5 -translate-x-1/2 bg-violet-400/80"
                                />
                                <span
                                    v-if="pack.showLineUp"
                                    class="absolute bottom-[18px] left-1/2 h-[calc(50%+0.5rem)] w-0.5 -translate-x-1/2 bg-violet-400/80"
                                />
                                <span
                                    class="relative z-[1] flex size-4 items-center justify-center rounded-full bg-violet-100 text-violet-700 ring-2 ring-white"
                                >
                                    <Link2 class="size-2.5" :stroke-width="2.5" />
                                </span>
                            </div>
                        </TableCell>
                    </TableRow>
                </DataTable>

                <div
                    v-if="hasMorePages && !isLoadingMore"
                    ref="loadMoreSentinel"
                    class="h-4 w-full"
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
                    :total="totalShown"
                    :showing="showingCount"
                    :has-more="hasMorePages"
                    :from="paginationFrom"
                    :to="paginationTo"
                    singular-label="orden"
                    plural-label="órdenes"
                />
            </div>
        </div>

        <SlideOverShell
            :show="detailOpen"
            close-only-header
            tabs-header
            compact-header
            fill-height
            mobile-full-bleed
            :max-width="ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS"
            accessibility-title="Detalle de orden"
            @close="closeDetail"
        >
            <template #header>
                <div class="min-w-0 space-y-1.5">
                    <div class="flex h-7 min-w-0 items-center sm:h-8">
                        <h2
                            class="min-w-0 truncate text-[13px] font-semibold tracking-tight text-slate-900 tabular-nums sm:text-[14px]"
                            :title="`Orden #${detailOrderNumber}`"
                        >
                            #{{ detailOrderNumber }}
                        </h2>
                    </div>
                    <Tabs v-model="detailTab" class="min-w-0">
                        <TabsList :class="SLIDE_OVER_TABS_LIST_CLASS">
                            <TabsTrigger
                                value="details"
                                :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                                title="Detalles"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <ClipboardList class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                    Detalles
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                v-if="detailMeta?.hasShipment"
                                value="shipment"
                                :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                                title="Envío"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <Package class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                    Envío
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                value="messages"
                                :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                                title="Mensajes"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <MessageCircle class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                    Mensajes
                                    <span
                                        v-if="(detailMeta?.buyerInboundCount ?? 0) > 0"
                                        class="inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-brand px-1 text-[9px] font-semibold leading-none text-white"
                                    >
                                        {{ detailMeta?.buyerInboundCount }}
                                    </span>
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                v-if="detailMeta?.hasAds"
                                value="ads"
                                :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                                title="Publicidad"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <Megaphone class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                    Publicidad
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                value="invoice"
                                :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                                title="Factura"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <Receipt class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                    Factura
                                </span>
                            </TabsTrigger>
                            <TabsTrigger
                                value="claims"
                                :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                                title="Reclamos"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <ShieldAlert class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                    Reclamos
                                    <span
                                        v-if="(detailMeta?.claimsOpenedCount ?? 0) > 0"
                                        class="inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-brand px-1 text-[9px] font-semibold leading-none text-white"
                                    >
                                        {{ detailMeta?.claimsOpenedCount }}
                                    </span>
                                </span>
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                </div>
            </template>

            <template #header-actions>
                <ConnectionChip
                    v-if="detailConnectionForChip"
                    class="mr-0.5 max-w-[7.5rem] sm:max-w-[14rem]"
                    :connection="detailConnectionForChip"
                    :account-only="false"
                    :compact="false"
                />
                <Button
                    v-if="canSyncSelectedOrder"
                    type="button"
                    size="icon"
                    variant="outline"
                    class="size-7 shrink-0 rounded-full sm:size-8"
                    :disabled="headerSyncing || !selectedOrderId"
                    :title="headerSyncing ? 'Actualizando…' : 'Resincronizar con Mercado Libre'"
                    :aria-label="headerSyncing ? 'Actualizando…' : 'Resincronizar con Mercado Libre'"
                    @click="syncSelectedOrder"
                >
                    <RefreshCw class="size-3.5" :class="{ 'animate-spin': headerSyncing }" />
                </Button>
            </template>

            <OrderDetailPanel
                v-if="selectedOrderId"
                ref="orderDetailPanelRef"
                :order-id="selectedOrderId"
                :selected-tab="detailTab"
                @update:selected-tab="detailTab = $event"
                @meta="detailMeta = $event"
                @open-shipment="openShipment"
                @open-timeline-step="onTimelineStep"
                @open-buyer="openBuyer"
                @open-product="openProductFromLine"
            />
        </SlideOverShell>

        <ShipmentSummarySlideOver
            :show="shipmentOpen"
            :shipment-id="selectedShipmentId ?? undefined"
            @close="closeShipment"
            @open-order="onShipmentOpenOrder"
        />

        <OrderStockReservationsSlideOver
            :show="stockOpen"
            :order-id="stockOrderId ?? undefined"
            @close="closeStock"
        />

        <OrderTimelineStepSlideOver
            :show="stepOpen"
            :step-key="stepKey ?? undefined"
            :order="stepOrder ?? undefined"
            @close="closeStep"
        />

        <OrderBuyerSlideOver
            :show="buyerOpen"
            :order="buyerOrder ?? undefined"
            @close="closeBuyer"
            @open-order="onBuyerOpenOrder"
        />

        <ProductDetailSlideOver
            :show="productSlideOpen"
            :product-id="selectedProductId"
            :ml-item-id="selectedMlItemId"
            :initial-tab="productSlideInitialTab"
            @close="closeProductSlide"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
.order-live-new {
    animation: order-live-highlight 2.5s ease-out;
}

@keyframes order-live-highlight {
    0% {
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.45);
        background-color: rgba(59, 130, 246, 0.06);
    }
    100% {
        box-shadow: none;
        background-color: transparent;
    }
}
</style>
