<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { buildExportSelectionPayload } from '@/composables/buildExportSelectionPayload';
import DataTable from '@/Components/App/DataTable.vue';
import FilterBar from '@/Components/App/FilterBar.vue';
import Badge from '@/Components/ui/Badge.vue';
import Input from '@/Components/ui/Input.vue';
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
import { useRecordSelection } from '@/composables/useRecordSelection';
import { Head, router } from '@inertiajs/vue3';
import { claimResolutionLabel, claimStatusLabel } from '@/lib/formatOrderMessage';
import { formatDateTime } from '@/lib/utils';
import Button from '@/Components/ui/Button.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import { ClipboardList, MessageCircle, Package, Receipt, RefreshCw, ShieldAlert } from 'lucide-vue-next';

const OrderDetailPanel = defineAsyncComponent(
    () => import('@/Components/Orders/OrderDetailPanel.vue'),
);

interface ClaimOrder {
    id: number;
    external_order_id: string | null;
    status: string;
}

interface ClaimRow {
    id: number;
    external_claim_id: string | null;
    type: string | null;
    stage: string | null;
    status: string;
    reason: string | null;
    resolution_reason: string | null;
    order_id: number | null;
    opened_at: string | null;
    order?: ClaimOrder | null;
}

interface PaginatedClaims {
    data: ClaimRow[];
    total?: number;
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
    claims: PaginatedClaims;
    filters: {
        q: string;
        status: string;
    };
}>();

const search = ref(props.filters?.q ?? '');
const status = ref(props.filters?.status ?? '');

const detailOpen = ref(false);
const selectedOrderId = ref<number | null>(null);
const selectedClaimOrder = ref<ClaimOrder | null>(null);
const detailTab = ref('claims');
const detailMeta = ref<DetailMeta | null>(null);
const orderDetailPanelRef = ref<{ syncNow?: () => Promise<void> } | null>(null);
const headerSyncing = ref(false);

const rows = computed(() => props.claims?.data ?? []);

const detailOrderNumber = computed(() => {
    const fromMeta = detailMeta.value?.externalOrderId;
    if (fromMeta != null && fromMeta !== '') return fromMeta;
    return selectedClaimOrder.value?.external_order_id ?? selectedOrderId.value;
});

const detailConnectionLabel = computed(
    () => detailMeta.value?.connectionLabel ?? detailMeta.value?.channelLabel ?? 'Mercado Libre',
);

const detailConnectionForChip = computed(() => {
    if (!detailConnectionLabel.value && detailMeta.value?.connectionId == null) {
        return null;
    }
    return {
        id: detailMeta.value?.connectionId ?? 0,
        provider: 'mercadolibre',
        display_name: detailMeta.value?.connectionLabel ?? detailConnectionLabel.value,
        color: detailMeta.value?.connectionColor ?? null,
    };
});

const canSyncSelectedOrder = computed(() => {
    if (detailMeta.value?.canSync != null) return detailMeta.value.canSync;
    return Boolean(selectedOrderId.value);
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

function applyFilters() {
    router.get(
        route('claims.index'),
        {
            q: search.value || undefined,
            status: status.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});

watch(status, () => applyFilters());

function openOrder(claim: ClaimRow) {
    if (!claim.order_id) return;
    selectedOrderId.value = claim.order_id;
    selectedClaimOrder.value = claim.order ?? null;
    detailTab.value = 'claims';
    detailMeta.value = null;
    headerSyncing.value = false;
    detailOpen.value = true;
}

function closeDetail() {
    detailOpen.value = false;
    selectedOrderId.value = null;
    selectedClaimOrder.value = null;
    detailTab.value = 'claims';
    detailMeta.value = null;
    headerSyncing.value = false;
}

function statusVariant(value: string) {
    if (value === 'opened') return 'danger';
    if (value === 'closed') return 'secondary';
    return 'outline';
}

const claimsTotalCount = computed(
    () => Number(props.claims.total ?? rows.value.length) || 0,
);

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
    loadedRows: rows,
    totalCount: claimsTotalCount,
    summableColumns: computed(() => []),
    currentQuery: () => ({
        q: props.filters.q || undefined,
        status: props.filters.status || undefined,
    }),
    endpoints: {
        allIds: () => route('claims.all-ids'),
        filteredSums: () => route('claims.filtered-sums'),
    },
    itemLabel: 'reclamos',
});
</script>

<template>
    <Head title="Reclamos" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Reclamos"
                    description="Reclamos postventa sincronizados desde Mercado Libre. Haz clic para abrir la orden."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="claims"
                            :get-payload="() => buildExportSelectionPayload({
                                selectedIds,
                                allPagesSelected,
                                totalCount: claimsTotalCount,
                            })"
                        />
                    </template>
                </PageHeader>

                <FilterBar>
                    <Input
                        v-model="search"
                        placeholder="Buscar reclamos…"
                        class="max-w-sm"
                    />
                    <select
                        v-model="status"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todos los estados</option>
                        <option value="opened">Abiertos</option>
                        <option value="closed">Cerrados</option>
                    </select>
                </FilterBar>

                <RecordSelectionBar
                    :has-selection="hasSelection"
                    :bulk-selection-props="bulkSelectionProps"
                    @select-all-filtered="selectAllInFilteredUniverse"
                    @clear-selection="clearSelection"
                    @header-toggle="onHeaderCheckboxChange"
                />

                <DataTable
                    :is-empty="rows.length === 0"
                    empty-title="Sin reclamos"
                    empty-description="Los reclamos aparecen tras eventos de webhook o sincronización."
                >
                    <template #head>
                        <TableHead class="w-10">
                            <TableSelectionCheckbox
                                :checked="allLoadedSelected"
                                :indeterminate="someLoadedSelected"
                                aria-label="Seleccionar reclamos cargados"
                                @change="onHeaderCheckboxChange"
                            />
                        </TableHead>
                        <TableHead>ID externo</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead>Resolución</TableHead>
                        <TableHead>Tipo</TableHead>
                        <TableHead>Etapa</TableHead>
                        <TableHead>Motivo</TableHead>
                        <TableHead>Orden</TableHead>
                        <TableHead>Abierto</TableHead>
                    </template>
                    <TableRow
                        v-for="claim in rows"
                        :key="claim.id"
                        :class="claim.order_id ? 'cursor-pointer hover:bg-muted/50' : 'opacity-80'"
                        @click="openOrder(claim)"
                    >
                        <TableCell class="w-10" @click.stop>
                            <TableSelectionCheckbox
                                :checked="isSelected(claim.id)"
                                aria-label="Seleccionar reclamo"
                                @change="toggle(claim.id)"
                            />
                        </TableCell>
                        <TableCell class="font-mono text-xs">
                            {{ claim.external_claim_id ?? '—' }}
                        </TableCell>
                        <TableCell>
                            <Badge :variant="statusVariant(claim.status)">
                                {{ claimStatusLabel(claim.status) }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <Badge
                                v-if="claimResolutionLabel(claim.resolution_reason)"
                                variant="success"
                            >
                                {{ claimResolutionLabel(claim.resolution_reason) }}
                            </Badge>
                            <span v-else class="text-xs text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell class="capitalize">{{ claim.type ?? '—' }}</TableCell>
                        <TableCell class="capitalize">{{ claim.stage ?? '—' }}</TableCell>
                        <TableCell>{{ claim.reason ?? '—' }}</TableCell>
                        <TableCell>
                            <template v-if="claim.order_id">
                                <span class="font-mono text-xs">
                                    #{{ claim.order?.external_order_id ?? claim.order_id }}
                                </span>
                            </template>
                            <span v-else class="text-xs text-muted-foreground">
                                Orden no sincronizada
                            </span>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ claim.opened_at ? formatDateTime(claim.opened_at) : '—' }}
                        </TableCell>
                    </TableRow>
                </DataTable>
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
            />
        </SlideOverShell>
    </AuthenticatedLayout>
</template>
