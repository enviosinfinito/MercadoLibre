<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface ChannelPrice {
    id: number;
    connection_id: number | null;
    provider: string | null;
    external_item_id: string | null;
    price_amount: string | number | null;
    currency_code: string | null;
    markup_pct: string | number | null;
    delta_pct: number | null;
    price_synced_at: string | null;
}

interface PriceRow {
    id: number;
    sku: string;
    name: string | null;
    base_price_amount: string | number | null;
    base_price_currency: string | null;
    channels: ChannelPrice[];
}

const props = defineProps<{
    rows: { data: PriceRow[]; links?: Array<{ url: string | null; label: string; active: boolean }> };
    connections: Array<{ id: number; provider: string }>;
    outbound_dry_run: boolean;
}>();

const drafts = reactive<Record<number, string>>({});

function initDraft(ch: ChannelPrice) {
    if (drafts[ch.id] === undefined) {
        drafts[ch.id] = ch.price_amount != null ? String(ch.price_amount) : '';
    }
}

props.rows.data.forEach((row) => row.channels.forEach(initDraft));

function savePrice(ch: ChannelPrice) {
    const form = useForm({
        price_amount: drafts[ch.id] || null,
        sync: true,
        dry_run: props.outbound_dry_run,
    });
    form.put(route('prices.update', ch.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Precios" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Precios"
                    description="Precio base y márgenes por canal de venta."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="prices"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                    </template>
                </PageHeader>

                <p class="mb-4 text-sm text-muted-foreground">
                    Outbound dry-run del workspace:
                    <strong>{{ outbound_dry_run ? 'activo' : 'live' }}</strong>
                </p>

                <DataTable
                    :is-empty="rows.data.length === 0"
                    empty-title="Sin variantes"
                    empty-description="Define productos y matching para gestionar precios por canal."
                >
                    <template #head>
                        <TableHead>SKU</TableHead>
                        <TableHead>Base</TableHead>
                        <TableHead>Por canal</TableHead>
                    </template>
                    <TableRow
                        v-for="row in rows.data"
                        :key="row.id"
                    >
                        <TableCell>
                            <div class="font-mono text-xs">{{ row.sku }}</div>
                            <div class="text-sm text-slate-700">{{ row.name ?? '—' }}</div>
                        </TableCell>
                        <TableCell>
                            <MoneyText
                                v-if="row.base_price_amount != null"
                                :amount="row.base_price_amount"
                                :currency="row.base_price_currency ?? 'MXN'"
                            />
                            <span
                                v-else
                                class="text-sm text-muted-foreground"
                            >Sin precio base</span>
                        </TableCell>
                        <TableCell>
                            <div
                                v-if="row.channels.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                Sin listings matched
                            </div>
                            <div
                                v-else
                                class="space-y-3"
                            >
                                <div
                                    v-for="ch in row.channels"
                                    :key="ch.id"
                                    class="flex flex-wrap items-end gap-2 rounded-md border border-slate-200 bg-slate-50 p-2"
                                >
                                    <div class="min-w-[6rem]">
                                        <p class="text-xs font-semibold capitalize text-slate-700">
                                            {{ ch.provider ?? 'canal' }}
                                        </p>
                                        <p class="font-mono text-[10px] text-muted-foreground">
                                            {{ ch.external_item_id }}
                                        </p>
                                    </div>
                                    <Input
                                        v-model="drafts[ch.id]"
                                        type="number"
                                        step="0.01"
                                        class="h-8 w-28"
                                        placeholder="Precio"
                                    />
                                    <Badge
                                        v-if="ch.delta_pct != null"
                                        :variant="ch.delta_pct >= 0 ? 'success' : 'warning'"
                                    >
                                        {{ ch.delta_pct >= 0 ? '+' : '' }}{{ ch.delta_pct }}%
                                    </Badge>
                                    <Button
                                        type="button"
                                        size="sm"
                                        @click="savePrice(ch)"
                                    >
                                        Aplicar y sync
                                    </Button>
                                    <span
                                        v-if="ch.price_synced_at"
                                        class="text-[10px] text-muted-foreground"
                                    >
                                        sync {{ ch.price_synced_at }}
                                    </span>
                                </div>
                            </div>
                        </TableCell>
                    </TableRow>
                </DataTable>

                <div
                    v-if="rows.links?.length"
                    class="mt-4 flex flex-wrap gap-2"
                >
                    <Link
                        v-for="link in rows.links"
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
