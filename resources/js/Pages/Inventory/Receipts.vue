<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import ReceiptsSearchAndFilters from '@/Components/Inventory/ReceiptsSearchAndFilters.vue';
import StockDetailSlideOver from '@/Components/Inventory/StockDetailSlideOver.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, Link } from '@inertiajs/vue3';
import { formatDateTime } from '@/lib/utils';
import { ref } from 'vue';

const props = defineProps<{
    receipts: {
        data: Array<{
            id: number;
            qty_original: string;
            qty_remaining: string;
            qty_expected?: string | null;
            purchase_order_line_id?: number | null;
            unit_cost_amount: string;
            unit_cost_currency: string;
            notes: string | null;
            received_at: string | null;
            variant: { id: number; sku: string; name: string | null } | null;
            warehouse: { id: number; code: string; name: string } | null;
        }>;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    purchase_orders: Array<{
        id: number;
        supplier_name: string;
        status: string;
        ordered_at: string | null;
        lines_count: number;
        qty_ordered: string;
        qty_received: string;
        lines: Array<{
            id: number;
            variant_id: number;
            sku: string | null;
            qty_ordered: string;
            qty_received: string;
            variance: string;
        }>;
    }>;
    warehouses: Array<{ id: number; code: string; name: string }>;
    filters: { q: string; variant_id?: number | null; warehouse_id: number | null };
}>();

const selectedVariantId = ref<number | null>(null);

function statusLabel(status: string) {
    return (
        {
            draft: 'Borrador',
            ordered: 'Pedida',
            receiving: 'Recibiendo',
            closed: 'Cerrada',
        }[status] ?? status
    );
}

function fmtQty(v: string | number | null | undefined) {
    if (v == null || v === '') return '—';
    return Number(v).toLocaleString('es-MX', { maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Ingresos de inventario" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Ingresos"
                    description="Órdenes de compra y recepciones en tu almacén."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="receipts"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                        <Link
                            :href="route('inventory.purchase-orders.create')"
                            class="inline-flex h-9 items-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Nueva OC
                        </Link>
                        <Link
                            :href="route('inventory.receipts.create')"
                            class="inline-flex h-9 items-center rounded-md bg-brand px-3 text-sm font-medium text-white hover:opacity-90"
                        >
                            Recibo suelto
                        </Link>
                    </template>
                </PageHeader>

                <ReceiptsSearchAndFilters
                    :filters="filters"
                    :warehouses="warehouses"
                />

                <section class="mb-8">
                    <h2 class="mb-3 text-sm font-semibold text-slate-900">Órdenes de compra</h2>
                    <DataTable
                        :is-empty="purchase_orders.length === 0"
                        empty-title="Sin órdenes de compra"
                        empty-description="Crea una OC al proveedor para recibir con discrepancia."
                    >
                        <template #head>
                            <TableHead>Fecha</TableHead>
                            <TableHead>Proveedor</TableHead>
                            <TableHead>Estado</TableHead>
                            <TableHead>Pedidas</TableHead>
                            <TableHead>Recibidas</TableHead>
                            <TableHead>SKU</TableHead>
                        </template>
                        <TableRow
                            v-for="po in purchase_orders"
                            :key="po.id"
                            class="cursor-pointer"
                            @click="() => (window.location.href = route('inventory.purchase-orders.show', po.id))"
                        >
                            <TableCell class="text-xs text-muted-foreground">
                                {{ formatDateTime(po.ordered_at) }}
                            </TableCell>
                            <TableCell>{{ po.supplier_name }}</TableCell>
                            <TableCell>
                                <Badge variant="secondary">{{ statusLabel(po.status) }}</Badge>
                            </TableCell>
                            <TableCell>{{ fmtQty(po.qty_ordered) }}</TableCell>
                            <TableCell>{{ fmtQty(po.qty_received) }}</TableCell>
                            <TableCell class="font-mono text-xs">
                                <button
                                    v-for="line in po.lines"
                                    :key="line.id"
                                    type="button"
                                    class="mr-2 text-brand hover:underline"
                                    @click.stop="selectedVariantId = line.variant_id"
                                >
                                    {{ line.sku ?? '—' }}
                                </button>
                            </TableCell>
                        </TableRow>
                    </DataTable>
                </section>

                <section>
                    <h2 class="mb-3 text-sm font-semibold text-slate-900">Recepciones</h2>
                    <DataTable
                        :is-empty="receipts.data.length === 0"
                        empty-title="Sin recepciones"
                        empty-description="Registra un ingreso para crear capas de costo."
                    >
                        <template #head>
                            <TableHead>Fecha</TableHead>
                            <TableHead>SKU</TableHead>
                            <TableHead>Almacén</TableHead>
                            <TableHead>Cantidad</TableHead>
                            <TableHead>Esperada</TableHead>
                            <TableHead>Restante</TableHead>
                            <TableHead>Costo unit.</TableHead>
                        </template>
                        <TableRow
                            v-for="row in receipts.data"
                            :key="row.id"
                        >
                            <TableCell class="text-xs text-muted-foreground">
                                {{ formatDateTime(row.received_at) }}
                            </TableCell>
                            <TableCell>
                                <button
                                    v-if="row.variant"
                                    type="button"
                                    class="font-mono text-xs text-brand hover:underline"
                                    @click="selectedVariantId = row.variant.id"
                                >
                                    {{ row.variant.sku }}
                                </button>
                                <span v-else class="font-mono text-xs">—</span>
                            </TableCell>
                            <TableCell>{{ row.warehouse?.code ?? '—' }}</TableCell>
                            <TableCell>{{ Number(row.qty_original) }}</TableCell>
                            <TableCell>{{ row.qty_expected != null ? Number(row.qty_expected) : '—' }}</TableCell>
                            <TableCell>{{ Number(row.qty_remaining) }}</TableCell>
                            <TableCell>
                                {{ row.unit_cost_amount }} {{ row.unit_cost_currency }}
                            </TableCell>
                        </TableRow>
                    </DataTable>

                    <div
                        v-if="receipts.links?.length"
                        class="mt-4 flex flex-wrap gap-2"
                    >
                        <Link
                            v-for="link in receipts.links"
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
                </section>
            </div>
        </div>

        <StockDetailSlideOver
            :show="selectedVariantId != null"
            :variant-id="selectedVariantId ?? undefined"
            @close="selectedVariantId = null"
        />
    </AuthenticatedLayout>
</template>
