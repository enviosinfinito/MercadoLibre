<script setup lang="ts">
import { computed, defineAsyncComponent, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { buildExportSelectionPayload } from '@/composables/buildExportSelectionPayload';
import DataTable from '@/Components/App/DataTable.vue';
import StackedData from '@/Components/App/StackedData.vue';
import MissingCostAlertBanner from '@/Components/Catalog/MissingCostAlertBanner.vue';
import ProductsSearchAndFilters from '@/Components/Products/SearchAndFilters.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import TableSelectionCheckbox from '@/Components/ui/TableSelectionCheckbox.vue';
import RecordSelectionBar from '@/Components/ui/ListToolbar/RecordSelectionBar.vue';
import { useRecordSelection } from '@/composables/useRecordSelection';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Button from '@/Components/ui/Button.vue';
import { providerLabel } from '@/lib/connectionLabel';
import { toCodeCase, toTitleCase } from '@/lib/formatDisplayText';
import { ExternalLink } from 'lucide-vue-next';

const ProductSlideOver = defineAsyncComponent(
    () => import('@/Components/Products/ProductSlideOver.vue'),
);

interface MatchedListingPreview {
    id: number;
    title: string | null;
    external_item_id: string | null;
    permalink: string | null;
    provider: string | null;
}

interface ProductRow {
    id: number;
    name: string;
    description: string | null;
    status: string;
    variants_count?: number;
    matched_listings_count?: number;
    matched_listings?: MatchedListingPreview[];
    missing_cost?: boolean;
    deleted_at?: string | null;
}

interface PaginatedProducts {
    data: ProductRow[];
    total?: number;
}

const props = defineProps<{
    products: PaginatedProducts;
    archived: boolean;
    without_cost?: boolean;
    missing_cost_count?: number;
}>();

const clientQ = ref('');

const productSlideOpen = ref(false);
const selectedProductId = ref<number | null>(null);

function openProduct(id: number) {
    selectedProductId.value = id;
    productSlideOpen.value = true;
}

function closeProductSlide() {
    productSlideOpen.value = false;
    selectedProductId.value = null;
}

const listFilters = computed(() => ({
    q: clientQ.value,
    archived: props.archived,
    without_cost: Boolean(props.without_cost),
}));

const filtered = computed(() => {
    const q = clientQ.value.trim().toLowerCase();
    if (!q) {
        return props.products.data;
    }
    return props.products.data.filter((p) =>
        [p.name, p.status, String(p.id)].some((v) =>
            String(v).toLowerCase().includes(q),
        ),
    );
});

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as { success?: string } | undefined)?.success,
);

const withoutCost = computed(() => Boolean(props.without_cost));
const missingCostCount = computed(() => props.missing_cost_count ?? 0);

const enqueueStockSync = () => {
    router.post(route('products.stock-sync'), { dry_run: true });
};

const archiveProduct = (product: ProductRow) => {
    if (
        !confirm(
            `¿Archivar “${product.name}”? Puedes restaurarlo después desde Archivados.`,
        )
    ) {
        return;
    }

    router.delete(route('products.destroy', product.id));
};

const restoreProduct = (product: ProductRow) => {
    router.post(route('products.restore', product.id));
};

function listingPrimary(listing: MatchedListingPreview) {
    if (listing.title?.trim()) {
        return toTitleCase(listing.title);
    }
    if (listing.external_item_id) {
        return toCodeCase(listing.external_item_id);
    }
    return 'Listing';
}

function listingMarketplaceLabel(listing: MatchedListingPreview) {
    const label = providerLabel(listing.provider);
    return !label || label === '—' ? 'Mercado Libre' : label;
}

const productsTotalCount = computed(
    () => Number(props.products.total ?? props.products.data?.length ?? 0) || 0,
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
    loadedRows: filtered,
    totalCount: productsTotalCount,
    summableColumns: computed(() => []),
    currentQuery: () => ({
        archived: props.archived ? 1 : undefined,
        without_cost: props.without_cost ? 1 : undefined,
    }),
    endpoints: {
        allIds: () => route('products.all-ids'),
        filteredSums: () => route('products.filtered-sums'),
    },
    itemLabel: 'productos',
});
</script>

<template>
    <Head title="Productos" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <PageHeader
                    compact
                    title="Productos"
                    description="Catálogo de productos y variantes de este workspace."
                >
                    <template #actions>
                        <div class="flex items-center gap-1.5">
                            <ExportToolbarButton
                                target-module="products"
                                :get-payload="() => buildExportSelectionPayload({
                                    selectedIds,
                                    allPagesSelected,
                                    totalCount: productsTotalCount,
                                })"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 px-2.5 text-[11px]"
                                @click="enqueueStockSync"
                            >
                                Sync stock (dry run)
                            </Button>
                            <Link :href="route('products.create')">
                                <Button size="sm" class="h-7 px-2.5 text-[11px]">
                                    Nuevo producto
                                </Button>
                            </Link>
                        </div>
                    </template>
                </PageHeader>

                <p
                    v-if="flashSuccess"
                    class="mb-3 rounded-xl border border-emerald-200/80 bg-emerald-50 px-3 py-2 text-xs text-emerald-800"
                >
                    {{ flashSuccess }}
                </p>

                <MissingCostAlertBanner
                    v-if="withoutCost"
                    :count="missingCostCount"
                    filtered
                    class="mb-3 rounded-xl px-3 py-2 text-xs"
                />
                <MissingCostAlertBanner
                    v-else-if="missingCostCount > 0 && !archived"
                    :count="missingCostCount"
                    class="mb-3 rounded-xl px-3 py-2 text-xs"
                />

                <ProductsSearchAndFilters
                    :filters="listFilters"
                    @update:q="clientQ = $event"
                />

                <RecordSelectionBar
                    :has-selection="hasSelection"
                    :bulk-selection-props="bulkSelectionProps"
                    @select-all-filtered="selectAllInFilteredUniverse"
                    @clear-selection="clearSelection"
                    @header-toggle="onHeaderCheckboxChange"
                />

                <DataTable
                    compact
                    :is-empty="filtered.length === 0"
                    empty-title="Sin productos"
                    :empty-description="
                        withoutCost
                            ? 'No hay productos sin costo definido.'
                            : archived
                              ? 'No hay productos archivados.'
                              : 'Crea productos o sincroniza listings desde una conexión.'
                    "
                >
                    <template #head>
                        <TableHead class="w-10">
                            <TableSelectionCheckbox
                                :checked="allLoadedSelected"
                                :indeterminate="someLoadedSelected"
                                aria-label="Seleccionar productos cargados"
                                @change="onHeaderCheckboxChange"
                            />
                        </TableHead>
                        <TableHead>ID</TableHead>
                        <TableHead>Producto</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead>Variantes</TableHead>
                        <TableHead>Listings</TableHead>
                        <TableHead class="text-right">Acciones</TableHead>
                    </template>
                    <TableRow
                        v-for="product in filtered"
                        :key="product.id"
                        class="cursor-pointer"
                        @click="!archived && openProduct(product.id)"
                    >
                        <TableCell class="w-10" @click.stop>
                            <TableSelectionCheckbox
                                :checked="isSelected(product.id)"
                                aria-label="Seleccionar producto"
                                @change="toggle(product.id)"
                            />
                        </TableCell>
                        <TableCell class="tabular-nums text-[11px] text-muted-foreground">
                            {{ product.id }}
                        </TableCell>
                        <TableCell class="max-w-[18rem]">
                            <StackedData
                                :primary="product.name"
                                :secondary="product.description"
                                primary-kind="title"
                                secondary-kind="label"
                            />
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap items-center gap-1">
                                <Badge
                                    v-if="archived"
                                    variant="secondary"
                                    class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium capitalize"
                                >
                                    Archivado
                                </Badge>
                                <Badge
                                    v-else
                                    variant="secondary"
                                    class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium capitalize"
                                >
                                    {{ product.status }}
                                </Badge>
                                <Badge
                                    v-if="product.missing_cost"
                                    variant="warning"
                                    class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
                                >
                                    Sin costo
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell class="tabular-nums text-slate-600">
                            {{ product.variants_count ?? 0 }}
                        </TableCell>
                        <TableCell>
                            <div
                                v-if="(product.matched_listings?.length ?? 0) > 0"
                                class="max-w-xs space-y-1.5"
                            >
                                <div
                                    v-for="listing in product.matched_listings"
                                    :key="listing.id"
                                    class="min-w-0"
                                >
                                    <div
                                        class="text-[12px] font-medium tracking-tight text-slate-800 line-clamp-1"
                                    >
                                        {{ listingPrimary(listing) }}
                                    </div>
                                    <div
                                        v-if="listing.external_item_id && listing.title"
                                        class="text-[10px] text-muted-foreground"
                                    >
                                        {{ toCodeCase(listing.external_item_id) }}
                                    </div>
                                    <a
                                        v-if="listing.permalink"
                                        :href="listing.permalink"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-semibold text-brand hover:underline"
                                        :title="`Abrir en ${listingMarketplaceLabel(listing)} (nueva pestaña)`"
                                        @click.stop
                                    >
                                        Abrir en {{ listingMarketplaceLabel(listing) }}
                                        <ExternalLink class="size-3 shrink-0" aria-hidden="true" />
                                    </a>
                                    <span
                                        v-else
                                        class="mt-0.5 block text-[10px] text-muted-foreground"
                                    >
                                        Sin enlace público
                                    </span>
                                </div>
                            </div>
                            <span v-else class="text-[11px] text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell class="text-right" @click.stop>
                            <div class="flex flex-wrap items-center justify-end gap-1.5">
                                <template v-if="archived">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        class="h-7 px-2 text-[11px]"
                                        @click="restoreProduct(product)"
                                    >
                                        Restaurar
                                    </Button>
                                </template>
                                <template v-else>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        class="h-7 px-2 text-[11px]"
                                        @click="openProduct(product.id)"
                                    >
                                        Abrir
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        class="h-7 px-2 text-[11px]"
                                        @click="archiveProduct(product)"
                                    >
                                        Archivar
                                    </Button>
                                </template>
                            </div>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>

        <ProductSlideOver
            :show="productSlideOpen"
            :product-id="selectedProductId ?? undefined"
            @close="closeProductSlide"
            @saved="closeProductSlide"
        />
    </AuthenticatedLayout>
</template>
