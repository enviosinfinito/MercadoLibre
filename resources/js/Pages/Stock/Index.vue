<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { buildExportSelectionPayload } from '@/composables/buildExportSelectionPayload';
import DataTable from '@/Components/App/DataTable.vue';
import StackedData from '@/Components/App/StackedData.vue';
import StockSearchAndFilters from '@/Components/Stock/SearchAndFilters.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import TableSelectionCheckbox from '@/Components/ui/TableSelectionCheckbox.vue';
import RecordSelectionBar from '@/Components/ui/ListToolbar/RecordSelectionBar.vue';
import StockDetailSlideOver from '@/Components/Inventory/StockDetailSlideOver.vue';
import MarketplaceStockSlideOver from '@/Components/Inventory/MarketplaceStockSlideOver.vue';
import { useRecordSelection } from '@/composables/useRecordSelection';
import { formatSelectionNumeric } from '@/lib/selectionNumeric';
import {
    formatStockDepletionHint,
    formatStockDepletionLabel,
    stockDepletionSeverity,
} from '@/lib/stockForecast';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

interface ChannelCell {
    channel_listing_variant_id: number;
    connection_id: number | null;
    provider: string | null;
    external_item_id: string | null;
    listing_status: string | null;
    available_quantity: number | null;
    stock_synced_at: string | null;
}

interface StockForecast {
    units_sold_window: number;
    units_per_day: number;
    days_of_cover: number | null;
    stockout_date: string | null;
    window_days: number;
    low_stock: boolean;
}

interface StockRow {
    id: number;
    sku: string;
    name: string | null;
    product_id: number;
    quantity_on_hand: string;
    quantity_reserved: string;
    quantity_available: string;
    channels: ChannelCell[];
    forecast: StockForecast | null;
    flags: {
        unmatched: boolean;
        negative: boolean;
        low_stock: boolean;
        channel_mismatch: boolean;
    };
}

interface MarketplaceRow {
    id?: number;
    channel_listing_variant_id: number;
    variant_id: number | null;
    sku: string | null;
    title: string | null;
    external_item_id: string | null;
    listing_status: string | null;
    logistic_type: string | null;
    user_product_id: string | null;
    inventory_id: string | null;
    published_quantity: number | null;
    seller_warehouse_qty: string;
    meli_facility_qty: string;
    selling_address_qty: string;
    locations: Array<{
        location_type: string;
        store_id: string | null;
        quantity: string;
    }>;
    channel_stock_synced_at: string | null;
}

const props = defineProps<{
    rows: {
        data: StockRow[];
        total?: number;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    marketplace_rows: MarketplaceRow[];
    marketplace_total?: number;
    connections: Array<{ id: number; provider: string; external_user_id: string | null }>;
    warehouses: Array<{ id: number; code: string; name: string; is_default: boolean }>;
    filters: {
        q: string;
        warehouse_id: number | null;
        low_stock: boolean;
        unmatched: boolean;
        channel_mismatch: boolean;
        tab: 'internal' | 'marketplace';
    };
    outbound_dry_run: boolean;
    last_sync: {
        id: number;
        status: string;
        dry_run: boolean;
        processed_at: string | null;
        created_at: string | null;
        last_error_redacted: string | null;
    } | null;
}>();

const form = useForm({
    dry_run: props.outbound_dry_run,
    connection_id: null as number | null,
});

const marketplaceForm = useForm({
    connection_id: null as number | null,
});

const tab = computed(() => props.filters.tab ?? 'internal');
const selectedVariantId = ref<number | null>(null);
const selectedClvId = ref<number | null>(null);

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const variantId = Number(params.get('variant_id') || '');
    if (Number.isFinite(variantId) && variantId > 0) {
        selectedVariantId.value = variantId;
    }
});

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as { success?: string } | undefined)?.success,
);

function setTab(next: 'internal' | 'marketplace') {
    router.get(
        route('stock.index'),
        {
            tab: next,
            q: props.filters.q || undefined,
            warehouse_id: next === 'internal' ? props.filters.warehouse_id || undefined : undefined,
            low_stock: next === 'internal' && props.filters.low_stock ? true : undefined,
            unmatched: next === 'internal' && props.filters.unmatched ? true : undefined,
            channel_mismatch: next === 'internal' && props.filters.channel_mismatch ? true : undefined,
        },
        { preserveState: true, replace: true },
    );
}

function syncStock() {
    form.post(route('stock.sync'), { preserveScroll: true });
}

function syncMarketplace() {
    marketplaceForm.post(route('stock.sync-marketplace'), { preserveScroll: true });
}

function fmtQty(v: string | number | null | undefined) {
    if (v == null || v === '') return '—';
    return Number(v).toLocaleString('es-MX', { maximumFractionDigits: 2 });
}

function depletionLabel(row: StockRow) {
    return formatStockDepletionLabel(row.forecast);
}

function depletionHint(row: StockRow) {
    return formatStockDepletionHint(row.forecast);
}

function depletionTone(row: StockRow) {
    return stockDepletionSeverity(row.forecast);
}

function openMarketplaceRow(row: MarketplaceRow) {
    selectedVariantId.value = null;
    selectedClvId.value = row.channel_listing_variant_id;
}

function openInternalFromMarketplace(variantId: number) {
    selectedClvId.value = null;
    selectedVariantId.value = variantId;
}

function marketplaceSecondary(row: MarketplaceRow) {
    return row.sku?.trim() || row.external_item_id || null;
}

const qtyMeta = { style: 'decimal' as const };

const marketplaceLoadedRows = computed(() =>
    (props.marketplace_rows ?? []).map((row) => ({
        ...row,
        id: row.id ?? row.channel_listing_variant_id,
    })),
);

const loadedRows = computed(() =>
    tab.value === 'marketplace' ? marketplaceLoadedRows.value : (props.rows.data ?? []),
);

const totalCount = computed(() => {
    if (tab.value === 'marketplace') {
        return Number(props.marketplace_total ?? marketplaceLoadedRows.value.length) || 0;
    }
    return Number(props.rows.total ?? props.rows.data?.length ?? 0) || 0;
});

const summableColumns = computed(() => {
    if (tab.value === 'marketplace') {
        return [
            { key: 'published_quantity', label: 'Publicado', fieldMeta: qtyMeta },
            { key: 'seller_warehouse_qty', label: 'Seller WH', fieldMeta: qtyMeta },
            { key: 'meli_facility_qty', label: 'Full', fieldMeta: qtyMeta },
            { key: 'selling_address_qty', label: 'Selling', fieldMeta: qtyMeta },
        ];
    }
    return [
        { key: 'quantity_on_hand', label: 'On hand', fieldMeta: qtyMeta },
        { key: 'quantity_reserved', label: 'Reservado', fieldMeta: qtyMeta },
        { key: 'quantity_available', label: 'Disponible', fieldMeta: qtyMeta },
    ];
});

const {
    selectedIds,
    allPagesSelected,
    hasSelection,
    bulkSelectionProps,
    allLoadedSelected,
    someLoadedSelected,
    isSelected,
    toggle,
    onHeaderCheckboxChange,
    selectAllInFilteredUniverse,
    clearSelection,
} = useRecordSelection({
    loadedRows,
    totalCount,
    summableColumns,
    currentQuery: () => ({
        tab: tab.value,
        q: props.filters.q || undefined,
        warehouse_id: props.filters.warehouse_id || undefined,
        low_stock: props.filters.low_stock ? 1 : undefined,
        unmatched: props.filters.unmatched ? 1 : undefined,
        channel_mismatch: props.filters.channel_mismatch ? 1 : undefined,
    }),
    endpoints: {
        allIds: () => route('stock.all-ids'),
        filteredSums: () => route('stock.filtered-sums'),
    },
    formatNumeric: (n, meta) =>
        formatSelectionNumeric(n, (meta ?? {}) as {
            style?: 'currency' | 'decimal' | 'integer';
        }),
    itemLabel: 'registros',
});
</script>

<template>
    <Head title="Stock" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <PageHeader
                    compact
                    title="Stock"
                    description="Inventario interno y stock en warehouses de Mercado Libre."
                >
                    <template #actions>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <ExportToolbarButton
                                target-module="stock"
                                :get-payload="() => buildExportSelectionPayload({
                                    selectedIds,
                                    allPagesSelected,
                                    totalCount,
                                })"
                            />
                            <Link
                                :href="route('inventory.ledger.index')"
                                class="px-1 text-[11px] font-medium text-brand hover:underline"
                            >
                                Movimientos
                            </Link>
                            <Link
                                :href="route('inventory.full-operations.index')"
                                class="px-1 text-[11px] font-medium text-brand hover:underline"
                            >
                                Movimientos Full
                            </Link>
                            <Link
                                :href="route('inventory.warehouses.index')"
                                class="px-1 text-[11px] font-medium text-brand hover:underline"
                            >
                                Almacenes
                            </Link>
                            <Link
                                :href="route('inventory.receipts.index')"
                                class="px-1 text-[11px] font-medium text-brand hover:underline"
                            >
                                Ingresos
                            </Link>
                            <template v-if="tab === 'internal'">
                                <label
                                    class="ml-1 flex items-center gap-1.5 text-[11px] text-slate-600"
                                >
                                    <input
                                        v-model="form.dry_run"
                                        type="checkbox"
                                        class="rounded border-slate-300 text-brand focus:ring-brand"
                                    />
                                    Dry-run
                                </label>
                                <Button
                                    type="button"
                                    size="sm"
                                    class="h-7 px-2.5 text-[11px]"
                                    :disabled="form.processing"
                                    @click="syncStock"
                                >
                                    Push stock a ML
                                </Button>
                            </template>
                            <Button
                                v-else
                                type="button"
                                size="sm"
                                class="h-7 px-2.5 text-[11px]"
                                :disabled="marketplaceForm.processing"
                                @click="syncMarketplace"
                            >
                                Sincronizar warehouses ML
                            </Button>
                        </div>
                    </template>
                </PageHeader>

                <div
                    class="mb-3 inline-flex max-w-full flex-wrap gap-0.5 rounded-full bg-slate-100/90 p-0.5"
                >
                    <button
                        type="button"
                        class="inline-flex h-7 items-center rounded-full px-2.5 text-[12px] font-medium tracking-tight transition"
                        :class="
                            tab === 'internal'
                                ? 'bg-white text-slate-900 shadow-[0_1px_2px_rgba(15,23,42,0.08)]'
                                : 'text-slate-500 hover:text-slate-800'
                        "
                        @click="setTab('internal')"
                    >
                        Inventario interno
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-7 items-center rounded-full px-2.5 text-[12px] font-medium tracking-tight transition"
                        :class="
                            tab === 'marketplace'
                                ? 'bg-white text-slate-900 shadow-[0_1px_2px_rgba(15,23,42,0.08)]'
                                : 'text-slate-500 hover:text-slate-800'
                        "
                        @click="setTab('marketplace')"
                    >
                        Warehouses Mercado Libre
                    </button>
                </div>

                <p
                    v-if="flashSuccess"
                    class="mb-3 rounded-xl border border-emerald-200/80 bg-emerald-50 px-3 py-2 text-xs text-emerald-800"
                >
                    {{ flashSuccess }}
                </p>

                <StockSearchAndFilters
                    :filters="filters"
                    :warehouses="warehouses"
                />

                <RecordSelectionBar
                    :has-selection="hasSelection"
                    :bulk-selection-props="bulkSelectionProps"
                    @select-all-filtered="selectAllInFilteredUniverse"
                    @clear-selection="clearSelection"
                    @header-toggle="onHeaderCheckboxChange"
                />

                <template v-if="tab === 'internal'">
                    <Card class="mb-3">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div>
                                <p class="font-medium text-slate-900">Modo outbound</p>
                                <p class="text-muted-foreground">
                                    Workspace dry-run:
                                    <strong>{{ outbound_dry_run ? 'activo' : 'live' }}</strong>
                                </p>
                            </div>
                            <div
                                v-if="last_sync"
                                class="text-right text-[11px] text-muted-foreground"
                            >
                                Último sync #{{ last_sync.id }} ·
                                <span class="capitalize">{{ last_sync.status }}</span>
                            </div>
                        </div>
                    </Card>

                    <DataTable
                        compact
                        sticky-head
                        :is-empty="rows.data.length === 0"
                        empty-title="Sin variantes"
                        empty-description="Crea productos o importa listings y haz matching."
                    >
                        <template #head>
                            <TableHead class="w-10">
                                <TableSelectionCheckbox
                                    :checked="allLoadedSelected"
                                    :indeterminate="someLoadedSelected"
                                    aria-label="Seleccionar filas cargadas"
                                    @change="onHeaderCheckboxChange"
                                />
                            </TableHead>
                            <TableHead>Producto</TableHead>
                            <TableHead>On hand</TableHead>
                            <TableHead>Reservado</TableHead>
                            <TableHead>Disponible</TableHead>
                            <TableHead>Se acaba en</TableHead>
                            <TableHead>Canales</TableHead>
                            <TableHead>Alertas</TableHead>
                        </template>
                        <TableRow
                            v-for="row in rows.data"
                            :key="row.id"
                            class="cursor-pointer hover:bg-slate-50"
                            @click="selectedVariantId = row.id"
                        >
                            <TableCell class="w-10" @click.stop>
                                <TableSelectionCheckbox
                                    :checked="isSelected(row.id)"
                                    aria-label="Seleccionar variante"
                                    @change="toggle(row.id)"
                                />
                            </TableCell>
                            <TableCell class="max-w-[18rem]">
                                <Link
                                    :href="route('products.edit', row.product_id)"
                                    class="block min-w-0 hover:opacity-80"
                                    @click.stop
                                >
                                    <StackedData
                                        :primary="row.name"
                                        :secondary="row.sku"
                                        primary-kind="title"
                                        secondary-kind="code"
                                    />
                                </Link>
                            </TableCell>
                            <TableCell class="tabular-nums text-[12px] text-slate-600">
                                {{ fmtQty(row.quantity_on_hand) }}
                            </TableCell>
                            <TableCell class="tabular-nums text-[12px] text-slate-600">
                                {{ fmtQty(row.quantity_reserved) }}
                            </TableCell>
                            <TableCell class="tabular-nums text-[12px] font-semibold text-slate-900">
                                {{ fmtQty(row.quantity_available) }}
                            </TableCell>
                            <TableCell>
                                <div
                                    class="text-[12px] font-medium tabular-nums"
                                    :class="{
                                        'text-rose-700': depletionTone(row) === 'critical',
                                        'text-amber-700': depletionTone(row) === 'warning',
                                        'text-slate-700': !depletionTone(row),
                                    }"
                                    :title="depletionHint(row)"
                                >
                                    {{ depletionLabel(row) }}
                                </div>
                                <p
                                    v-if="row.forecast?.stockout_date && row.forecast.days_of_cover != null && row.forecast.days_of_cover > 0"
                                    class="text-[10px] text-muted-foreground"
                                >
                                    {{ row.forecast.stockout_date }}
                                </p>
                            </TableCell>
                            <TableCell>
                                <div
                                    v-if="row.channels.length === 0"
                                    class="text-[11px] text-muted-foreground"
                                >
                                    Sin match
                                </div>
                                <div
                                    v-else
                                    class="flex flex-wrap gap-1"
                                >
                                    <div
                                        v-for="ch in row.channels"
                                        :key="ch.channel_listing_variant_id"
                                        class="rounded-md border border-slate-200/80 bg-slate-50 px-1.5 py-0.5 text-[10px] text-slate-700"
                                    >
                                        <span class="font-semibold capitalize">{{ ch.provider }}</span>
                                        · qty {{ ch.available_quantity ?? '—' }}
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-wrap gap-1">
                                    <Badge
                                        v-if="row.flags.negative"
                                        variant="danger"
                                        class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
                                    >
                                        Negativo
                                    </Badge>
                                    <Badge
                                        v-if="row.flags.low_stock"
                                        variant="warning"
                                        class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
                                    >
                                        Bajo
                                    </Badge>
                                    <Badge
                                        v-if="row.flags.channel_mismatch"
                                        variant="warning"
                                        class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
                                    >
                                        Desfase
                                    </Badge>
                                </div>
                            </TableCell>
                        </TableRow>
                    </DataTable>

                    <div
                        v-if="rows.links?.length"
                        class="mt-3 flex flex-wrap gap-1.5"
                    >
                        <Link
                            v-for="link in rows.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            class="rounded-md px-2.5 py-1 text-[11px]"
                            :class="
                                link.active
                                    ? 'bg-brand text-white'
                                    : 'bg-white text-slate-600 ring-1 ring-slate-200'
                            "
                            v-html="link.label"
                        />
                    </div>
                </template>

                <template v-else>
                    <Card class="mb-3 text-xs text-slate-600">
                        Stock publicado en ML y desglose por ubicación (seller warehouse, Full, selling address).
                        Usa <strong>Sincronizar warehouses ML</strong> para enriquecer
                        <code class="text-[10px]">user_product_id</code> y tirar ubicaciones.
                    </Card>

                    <DataTable
                        compact
                        sticky-head
                        :is-empty="marketplace_rows.length === 0"
                        empty-title="Sin publicaciones ML"
                        empty-description="Sincroniza listings desde Conexiones y luego warehouses ML."
                    >
                        <template #head>
                            <TableHead class="w-10">
                                <TableSelectionCheckbox
                                    :checked="allLoadedSelected"
                                    :indeterminate="someLoadedSelected"
                                    aria-label="Seleccionar filas cargadas"
                                    @change="onHeaderCheckboxChange"
                                />
                            </TableHead>
                            <TableHead>Publicación</TableHead>
                            <TableHead>Logística</TableHead>
                            <TableHead>Publicado</TableHead>
                            <TableHead>Seller WH</TableHead>
                            <TableHead>Full</TableHead>
                            <TableHead>Selling addr</TableHead>
                            <TableHead>UP / Inv</TableHead>
                        </template>
                        <TableRow
                            v-for="row in marketplaceLoadedRows"
                            :key="row.id"
                            class="cursor-pointer hover:bg-slate-50"
                            @click="openMarketplaceRow(row)"
                        >
                            <TableCell class="w-10" @click.stop>
                                <TableSelectionCheckbox
                                    :checked="isSelected(row.id)"
                                    aria-label="Seleccionar publicación"
                                    @change="toggle(row.id)"
                                />
                            </TableCell>
                            <TableCell class="max-w-[18rem]">
                                <StackedData
                                    :primary="row.title"
                                    :secondary="marketplaceSecondary(row)"
                                    primary-kind="title"
                                    secondary-kind="code"
                                />
                            </TableCell>
                            <TableCell>
                                <Badge
                                    variant="muted"
                                    class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
                                >
                                    {{ row.logistic_type ?? '—' }}
                                </Badge>
                            </TableCell>
                            <TableCell class="tabular-nums text-[12px] font-semibold text-slate-900">
                                {{ fmtQty(row.published_quantity) }}
                            </TableCell>
                            <TableCell class="tabular-nums text-[12px] text-slate-600">
                                {{ fmtQty(row.seller_warehouse_qty) }}
                            </TableCell>
                            <TableCell class="tabular-nums text-[12px] text-slate-600">
                                {{ fmtQty(row.meli_facility_qty) }}
                            </TableCell>
                            <TableCell class="tabular-nums text-[12px] text-slate-600">
                                {{ fmtQty(row.selling_address_qty) }}
                            </TableCell>
                            <TableCell class="font-mono text-[10px] text-muted-foreground">
                                <div>{{ row.user_product_id ?? 'sin UP' }}</div>
                                <div>{{ row.inventory_id ?? 'sin inv' }}</div>
                            </TableCell>
                        </TableRow>
                    </DataTable>
                </template>
            </div>
        </div>

        <StockDetailSlideOver
            :show="selectedVariantId != null"
            :variant-id="selectedVariantId ?? undefined"
            @close="selectedVariantId = null"
            @changed="router.reload({ only: ['rows', 'marketplace_rows'] })"
        />

        <MarketplaceStockSlideOver
            :show="selectedClvId != null"
            :channel-listing-variant-id="selectedClvId ?? undefined"
            @close="selectedClvId = null"
            @open-variant="openInternalFromMarketplace"
        />
    </AuthenticatedLayout>
</template>
