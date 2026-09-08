<script setup lang="ts">
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { buildExportSelectionPayload } from '@/composables/buildExportSelectionPayload';
import DataTable from '@/Components/App/DataTable.vue';
import ShipmentsSearchAndFilters from '@/Components/Fulfillment/ShipmentsSearchAndFilters.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import TableSelectionCheckbox from '@/Components/ui/TableSelectionCheckbox.vue';
import RecordSelectionBar from '@/Components/ui/ListToolbar/RecordSelectionBar.vue';
import { useRecordSelection } from '@/composables/useRecordSelection';
import { Head, Link, router } from '@inertiajs/vue3';

interface ShipmentRow {
    id: number;
    external_shipment_id: string | null;
    order_id: number | null;
    status: string;
    carrier: string | null;
    tracking_number: string | null;
    shipped_at: string | null;
    delivered_at: string | null;
}

interface PaginatedShipments {
    data: ShipmentRow[];
    total?: number;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    shipments: PaginatedShipments;
    filters?: {
        q?: string;
    };
}>();

const search = computed({
    get: () => props.filters?.q ?? '',
    set: (q: string) => {
        router.get(
            route('shipments.index'),
            { q: q || undefined, page: 1 },
            { preserveState: true, replace: true },
        );
    },
});

const shipmentsTotalCount = computed(
    () => Number(props.shipments.total ?? props.shipments.data?.length ?? 0) || 0,
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
    loadedRows: computed(() => props.shipments.data ?? []),
    totalCount: shipmentsTotalCount,
    summableColumns: computed(() => []),
    currentQuery: () => ({
        q: props.filters?.q || undefined,
    }),
    endpoints: {
        allIds: () => route('shipments.all-ids'),
        filteredSums: () => route('shipments.filtered-sums'),
    },
    itemLabel: 'envíos',
});
</script>

<template>
    <Head title="Shipments" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Shipments"
                    description="Fulfillment shipments across marketplace orders."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="shipments"
                            :get-payload="() => buildExportSelectionPayload({
                                selectedIds,
                                allPagesSelected,
                                totalCount: shipmentsTotalCount,
                            })"
                        />
                    </template>
                </PageHeader>

                <ShipmentsSearchAndFilters v-model="search" />

                <RecordSelectionBar
                    :has-selection="hasSelection"
                    :bulk-selection-props="bulkSelectionProps"
                    @select-all-filtered="selectAllInFilteredUniverse"
                    @clear-selection="clearSelection"
                    @header-toggle="onHeaderCheckboxChange"
                />

                <DataTable
                    :is-empty="shipments.data.length === 0"
                    empty-title="No shipments"
                    empty-description="Shipments appear when orders are fulfilled."
                >
                    <template #head>
                        <TableHead class="w-10">
                            <TableSelectionCheckbox
                                :checked="allLoadedSelected"
                                :indeterminate="someLoadedSelected"
                                aria-label="Seleccionar envíos cargados"
                                @change="onHeaderCheckboxChange"
                            />
                        </TableHead>
                        <TableHead>ID</TableHead>
                        <TableHead>External</TableHead>
                        <TableHead>Order</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Carrier</TableHead>
                        <TableHead>Tracking</TableHead>
                        <TableHead>Shipped</TableHead>
                    </template>
                    <TableRow v-for="row in shipments.data" :key="row.id">
                        <TableCell class="w-10">
                            <TableSelectionCheckbox
                                :checked="isSelected(row.id)"
                                aria-label="Seleccionar envío"
                                @change="toggle(row.id)"
                            />
                        </TableCell>
                        <TableCell>{{ row.id }}</TableCell>
                        <TableCell class="font-mono text-xs">
                            {{ row.external_shipment_id ?? '—' }}
                        </TableCell>
                        <TableCell>{{ row.order_id ?? '—' }}</TableCell>
                        <TableCell>
                            <Badge variant="secondary" class="capitalize">
                                {{ row.status }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ row.carrier ?? '—' }}</TableCell>
                        <TableCell class="font-mono text-xs">
                            {{ row.tracking_number ?? '—' }}
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ row.shipped_at ?? '—' }}
                        </TableCell>
                    </TableRow>
                </DataTable>

                <div
                    v-if="shipments.links?.length"
                    class="mt-4 flex flex-wrap gap-2"
                >
                    <Link
                        v-for="link in shipments.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="rounded-md px-3 py-1 text-sm"
                        :class="
                            link.active
                                ? 'bg-brand text-white'
                                : 'bg-white text-slate-600 ring-1 ring-slate-200'
                        "
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
