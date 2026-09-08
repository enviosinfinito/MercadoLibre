<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import LedgerSearchAndFilters from '@/Components/Inventory/LedgerSearchAndFilters.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import StockDetailSlideOver from '@/Components/Inventory/StockDetailSlideOver.vue';
import { Head, Link } from '@inertiajs/vue3';
import { formatDateTime } from '@/lib/utils';
import { ref } from 'vue';

const props = defineProps<{
    entries: {
        data: Array<{
            id: number;
            movement_type: string;
            quantity_delta: string;
            quantity_after: string | null;
            occurred_at: string | null;
            variant: { id: number; sku: string; name: string | null } | null;
            warehouse: { id: number; code: string; name: string } | null;
        }>;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    warehouses: Array<{ id: number; code: string; name: string }>;
    filters: {
        q: string;
        variant_id?: number | null;
        movement_type: string;
        warehouse_id: number | null;
        from: string;
        to: string;
    };
    movement_types: string[];
}>();

const selectedVariantId = ref<number | null>(null);

</script>

<template>
    <Head title="Movimientos de inventario" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Movimientos"
                    description="Ledger append-only de inventario."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="ledger"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                        <Link
                            :href="route('stock.index')"
                            class="text-sm text-brand hover:underline"
                        >
                            Volver a Stock
                        </Link>
                    </template>
                </PageHeader>

                <LedgerSearchAndFilters
                    :filters="filters"
                    :warehouses="warehouses"
                    :movement-types="movement_types"
                />

                <DataTable
                    :is-empty="entries.data.length === 0"
                    empty-title="Sin movimientos"
                    empty-description="Los ingresos y reservas aparecerán aquí."
                >
                    <template #head>
                        <TableHead>Fecha</TableHead>
                        <TableHead>Tipo</TableHead>
                        <TableHead>SKU</TableHead>
                        <TableHead>Almacén</TableHead>
                        <TableHead>Delta</TableHead>
                        <TableHead>Después</TableHead>
                    </template>
                    <TableRow
                        v-for="row in entries.data"
                        :key="row.id"
                    >
                        <TableCell class="text-xs text-muted-foreground">
                            {{ formatDateTime(row.occurred_at) }}
                        </TableCell>
                        <TableCell class="capitalize">{{ row.movement_type }}</TableCell>
                        <TableCell class="font-mono text-xs">
                            <button
                                v-if="row.variant"
                                type="button"
                                class="text-brand hover:underline"
                                @click="selectedVariantId = row.variant.id"
                            >
                                {{ row.variant.sku }}
                            </button>
                            <span v-else>—</span>
                        </TableCell>
                        <TableCell>{{ row.warehouse?.code ?? '—' }}</TableCell>
                        <TableCell class="font-mono text-xs">{{ row.quantity_delta }}</TableCell>
                        <TableCell class="font-mono text-xs">{{ row.quantity_after ?? '—' }}</TableCell>
                    </TableRow>
                </DataTable>

                <div
                    v-if="entries.links?.length"
                    class="mt-4 flex flex-wrap gap-2"
                >
                    <Link
                        v-for="link in entries.links"
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

        <StockDetailSlideOver
            :show="selectedVariantId != null"
            :variant-id="selectedVariantId ?? undefined"
            @close="selectedVariantId = null"
        />
    </AuthenticatedLayout>
</template>
