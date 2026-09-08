<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { buildExportSelectionPayload } from '@/composables/buildExportSelectionPayload';
import DataTable from '@/Components/App/DataTable.vue';
import MissingCostAlertBanner from '@/Components/Catalog/MissingCostAlertBanner.vue';
import PublicationsSearchAndFilters from '@/Components/Publications/SearchAndFilters.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import TableSelectionCheckbox from '@/Components/ui/TableSelectionCheckbox.vue';
import RecordSelectionBar from '@/Components/ui/ListToolbar/RecordSelectionBar.vue';
import { connectionSurfaceStyle } from '@/lib/connectionColor';
import { useRecordSelection } from '@/composables/useRecordSelection';
import ProductDetailSlideOver from '@/Components/Products/ProductDetailSlideOver.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface ListingRow {
    id: number;
    title: string | null;
    status: string;
    external_item_id: string;
    permalink: string | null;
    platform?: string;
    matched_sku?: string | null;
    is_matched?: boolean;
    missing_cost?: boolean;
    pe_color?: string | null;
    pe_value?: number | null;
    provider: string;
    connection?: {
        id: number;
        provider: string;
        external_user_id?: string | null;
        display_name?: string | null;
        color?: string | null;
    } | null;
}

const peBadgeVariant = (color: string | null | undefined) => {
    if (!color) return 'muted'
    if (color.includes('green')) return 'success'
    if (color.includes('yellow') || color.includes('orange')) return 'warning'
    if (color.includes('red')) return 'danger'
    return 'muted'
}

const props = defineProps<{
    listings: {
        data: ListingRow[];
        total?: number;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    connections: Array<{
        id: number;
        provider: string;
        external_user_id: string | null;
        display_name: string | null;
        color?: string | null;
    }>;
    filters: {
        q: string;
        status: string;
        connection_id: number | null;
        matched: string;
        without_cost?: boolean;
    };
    listings_without_cost_count?: number;
}>();

const listingsWithoutCostCount = computed(() => props.listings_without_cost_count ?? 0);
const withoutCost = computed(() => Boolean(props.filters.without_cost));
const loadedRows = computed(() => props.listings.data ?? []);
const totalCount = computed(
    () => Number(props.listings.total ?? props.listings.data?.length ?? 0) || 0,
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
    loadedRows,
    totalCount,
    summableColumns: computed(() => []),
    currentQuery: () => ({
        q: props.filters.q || undefined,
        status: props.filters.status || undefined,
        connection_id: props.filters.connection_id || undefined,
        matched: props.filters.matched || undefined,
        without_cost: props.filters.without_cost ? 1 : undefined,
    }),
    endpoints: {
        allIds: () => route('publications.all-ids'),
        filteredSums: () => route('publications.filtered-sums'),
    },
    itemLabel: 'publicaciones',
});

function bulk(action: 'pause' | 'activate') {
    const form = useForm({ ids: [...selectedIds.value], action });
    form.post(route('publications.bulk'), {
        preserveScroll: true,
        onSuccess: () => {
            clearSelection();
        },
    });
}

function setStatus(listing: ListingRow, next: 'active' | 'paused') {
    const form = useForm({ status: next });
    form.put(route('publications.update', listing.id), { preserveScroll: true });
}

const productSlideOpen = ref(false);
const selectedListingId = ref<number | null>(null);
const selectedMlItemId = ref<string | null>(null);

function openListing(listing: ListingRow) {
    selectedListingId.value = listing.id;
    selectedMlItemId.value = listing.external_item_id || null;
    productSlideOpen.value = true;
}

function closeProductSlide() {
    productSlideOpen.value = false;
    selectedListingId.value = null;
    selectedMlItemId.value = null;
}
</script>

<template>
    <Head title="Publicaciones" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Publicaciones"
                    description="Listings importados: pausar, activar y matching."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="publications"
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
                            disabled
                            title="Próximamente: asistente de publicación"
                        >
                            Publicar nuevo
                        </Button>
                        <Button
                            as="a"
                            size="sm"
                            variant="outline"
                            :href="route('matching.index')"
                        >
                            Ir a matching
                        </Button>
                    </template>
                </PageHeader>

                <MissingCostAlertBanner
                    v-if="withoutCost"
                    :count="listingsWithoutCostCount"
                    filtered
                />
                <MissingCostAlertBanner
                    v-else-if="listingsWithoutCostCount > 0"
                    :count="listingsWithoutCostCount"
                />

                <PublicationsSearchAndFilters
                    :filters="filters"
                    :connections="connections"
                />

                <RecordSelectionBar
                    :has-selection="hasSelection"
                    :bulk-selection-props="bulkSelectionProps"
                    @select-all-filtered="selectAllInFilteredUniverse"
                    @clear-selection="clearSelection"
                    @header-toggle="onHeaderCheckboxChange"
                >
                    <template #actions>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 px-2 text-xs"
                            @click="bulk('pause')"
                        >
                            Pausar
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            class="h-7 px-2 text-xs"
                            @click="bulk('activate')"
                        >
                            Activar
                        </Button>
                    </template>
                </RecordSelectionBar>

                <DataTable
                    :is-empty="listings.data.length === 0"
                    empty-title="Sin publicaciones"
                    :empty-description="
                        withoutCost
                            ? 'No hay publicaciones activas sin costo definido.'
                            : 'Sincroniza listings desde Conexiones.'
                    "
                >
                    <template #head>
                        <TableHead class="w-10">
                            <TableSelectionCheckbox
                                :checked="allLoadedSelected"
                                :indeterminate="someLoadedSelected"
                                aria-label="Seleccionar publicaciones cargadas"
                                @change="onHeaderCheckboxChange"
                            />
                        </TableHead>
                        <TableHead>Publicación</TableHead>
                        <TableHead>Canal</TableHead>
                        <TableHead>SKU</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead>Acciones</TableHead>
                    </template>
                    <TableRow
                        v-for="listing in listings.data"
                        :key="listing.id"
                        class="conn-row cursor-pointer"
                        :style="connectionSurfaceStyle(listing.connection?.color)"
                        @click="openListing(listing)"
                    >
                        <TableCell
                            class="w-10"
                            @click.stop
                        >
                            <TableSelectionCheckbox
                                :checked="isSelected(listing.id)"
                                aria-label="Seleccionar publicación"
                                @change="toggle(listing.id)"
                            />
                        </TableCell>
                        <TableCell>
                            <div class="font-medium text-slate-900 hover:text-brand">
                                {{ listing.title ?? listing.external_item_id }}
                            </div>
                            <div class="font-mono text-[10px] text-muted-foreground">
                                {{ listing.external_item_id }}
                            </div>
                            <a
                                v-if="listing.permalink"
                                :href="listing.permalink"
                                target="_blank"
                                class="text-xs text-brand hover:underline"
                                @click.stop
                            >
                                Ver en marketplace
                            </a>
                        </TableCell>
                        <TableCell>
                            <ConnectionChip
                                v-if="listing.connection"
                                :connection="listing.connection"
                            />
                            <Badge v-else variant="secondary" class="capitalize">
                                {{ listing.platform ?? listing.provider }}
                            </Badge>
                        </TableCell>
                        <TableCell class="font-mono text-xs">
                            <template v-if="listing.is_matched">
                                {{ listing.matched_sku }}
                            </template>
                            <Link
                                v-else
                                :href="route('matching.index')"
                                class="text-brand hover:underline"
                            >
                                Sin match
                            </Link>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge
                                    :variant="listing.status === 'active' ? 'success' : 'warning'"
                                    class="capitalize"
                                >
                                    {{ listing.status }}
                                </Badge>
                                <Badge
                                    v-if="listing.missing_cost"
                                    variant="warning"
                                >
                                    Sin costo
                                </Badge>
                                <Badge
                                    v-if="listing.pe_color"
                                    :variant="peBadgeVariant(listing.pe_color)"
                                    class="capitalize"
                                >
                                    Exp. {{ listing.pe_color }}{{ listing.pe_value != null ? ` · ${listing.pe_value}` : '' }}
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell @click.stop>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    v-if="listing.status !== 'paused'"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="setStatus(listing, 'paused')"
                                >
                                    Pausar
                                </Button>
                                <Button
                                    v-else
                                    type="button"
                                    size="sm"
                                    @click="setStatus(listing, 'active')"
                                >
                                    Activar
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </DataTable>

                <div
                    v-if="listings.links?.length"
                    class="mt-4 flex flex-wrap gap-2"
                >
                    <Link
                        v-for="link in listings.links"
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

        <ProductDetailSlideOver
            :show="productSlideOpen"
            :listing-id="selectedListingId"
            :ml-item-id="selectedMlItemId"
            initial-tab="publication"
            @close="closeProductSlide"
        />
    </AuthenticatedLayout>
</template>
