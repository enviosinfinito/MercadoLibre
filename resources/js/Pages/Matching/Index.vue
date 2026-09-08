<script setup lang="ts">
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, router } from '@inertiajs/vue3';

interface ListingInfo {
    id: number;
    title: string | null;
    external_item_id: string | null;
}

interface UnmatchedRow {
    id: number;
    sku_external: string | null;
    external_variation_id: string | null;
    status: string | null;
    listing?: ListingInfo | null;
}

interface VariantOption {
    id: number;
    sku: string;
    name: string | null;
}

interface PaginatedUnmatched {
    data: UnmatchedRow[];
}

const props = defineProps<{
    unmatched: PaginatedUnmatched;
    variants: VariantOption[];
}>();

const selected = ref<Record<number, number | ''>>({});

const assign = (rowId: number) => {
    const variantId = selected.value[rowId];
    if (!variantId) {
        return;
    }
    router.post(route('matching.store'), {
        channel_listing_variant_id: rowId,
        variant_id: variantId,
    });
};
</script>

<template>
    <Head title="Catalog Matching" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Catalog Matching"
                    description="Assign marketplace listing variants to catalog variants."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="matching"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                    </template>
                </PageHeader>

                <DataTable
                    :is-empty="unmatched.data.length === 0"
                    empty-title="All matched"
                    empty-description="No unmatched channel listing variants."
                >
                    <template #head>
                        <TableHead>ID</TableHead>
                        <TableHead>Listing</TableHead>
                        <TableHead>External SKU</TableHead>
                        <TableHead>Assign variant</TableHead>
                        <TableHead />
                    </template>
                    <TableRow v-for="row in unmatched.data" :key="row.id">
                        <TableCell>{{ row.id }}</TableCell>
                        <TableCell>
                            <div class="font-medium">
                                {{ row.listing?.title ?? '—' }}
                            </div>
                            <div class="font-mono text-xs text-muted-foreground">
                                {{ row.listing?.external_item_id ?? '—' }}
                            </div>
                        </TableCell>
                        <TableCell class="font-mono text-xs">
                            {{ row.sku_external ?? row.external_variation_id ?? '—' }}
                        </TableCell>
                        <TableCell>
                            <select
                                v-model="selected[row.id]"
                                class="h-9 w-full max-w-xs rounded-md border border-input bg-white px-2 text-sm"
                            >
                                <option value="">Select variant…</option>
                                <option
                                    v-for="v in variants"
                                    :key="v.id"
                                    :value="v.id"
                                >
                                    {{ v.sku }} — {{ v.name ?? '—' }}
                                </option>
                            </select>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                size="sm"
                                :disabled="!selected[row.id]"
                                @click="assign(row.id)"
                            >
                                Match
                            </Button>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
