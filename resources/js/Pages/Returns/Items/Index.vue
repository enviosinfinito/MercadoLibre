<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import Badge from '@/Components/ui/Badge.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import OrderDetailSlideOver from '@/Components/Orders/OrderDetailSlideOver.vue';
import ReturnProductSlideOver from '@/Components/Returns/ReturnProductSlideOver.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDateTime } from '@/lib/utils';
import { connectionFilterLabel } from '@/lib/connectionLabel';
import {
    connectionSurfaceStyle,
    resolveConnectionColor,
} from '@/lib/connectionColor';

interface ConnectionRow {
    id: number;
    provider: string;
    external_user_id?: string | null;
    display_name?: string | null;
    color?: string | null;
}

const props = defineProps<{
    period: { preset: string; from: string; to: string; label: string };
    filters: Record<string, any>;
    connections: ConnectionRow[];
    items: { data: Array<Record<string, any>>; total?: number };
}>();

const q = ref(props.filters.q ?? '');
const period = ref(props.filters.period ?? props.period.preset ?? 'last_30_days');
const outcome = ref(props.filters.outcome ?? '');
const reasonGroup = ref(props.filters.reason_group ?? '');

const orderSlideOpen = ref(false);
const selectedOrderId = ref<number | null>(null);
const selectedExternalOrderId = ref<string | number | null>(null);
const selectedOrderConnection = ref<ConnectionRow | null>(null);

function openOrder(row: Record<string, any>) {
    if (!row.order_id) return;
    selectedOrderId.value = Number(row.order_id);
    selectedExternalOrderId.value = row.external_order_id ?? row.order_id;
    selectedOrderConnection.value = row.connection ?? null;
    orderSlideOpen.value = true;
}

function closeOrder() {
    orderSlideOpen.value = false;
    selectedOrderId.value = null;
    selectedExternalOrderId.value = null;
    selectedOrderConnection.value = null;
}

const productSlideOpen = ref(false);
const selectedProductKey = ref<string | null>(null);

function openProduct(row: Record<string, any>) {
    const key = row.product_id != null
        ? String(row.product_id)
        : row.ml_item_id
            ? `ml:${row.ml_item_id}`
            : null;
    if (!key) return;
    selectedProductKey.value = key;
    productSlideOpen.value = true;
}

function closeProduct() {
    productSlideOpen.value = false;
    selectedProductKey.value = null;
}

const allConnectionIds = computed(() => props.connections.map((c) => c.id));
const selectedConnectionIds = computed(() => {
    const fromFilters = props.filters?.connection_ids ?? [];
    if (!Array.isArray(fromFilters) || !fromFilters.length) {
        const single = props.filters?.connection_id;
        if (single) return [Number(single)];
        return allConnectionIds.value;
    }
    return fromFilters.map((id: number | string) => Number(id));
});

const isFilteringConnections = computed(
    () =>
        props.connections.length > 1 &&
        selectedConnectionIds.value.length > 0 &&
        selectedConnectionIds.value.length < props.connections.length,
);

function isConnectionSelected(id: number) {
    return selectedConnectionIds.value.includes(id);
}

function apply(extra: Record<string, unknown> = {}) {
    const allSelected =
        selectedConnectionIds.value.length === allConnectionIds.value.length;

    router.get(
        route('returns.items.index'),
        {
            period: period.value,
            q: q.value || undefined,
            outcome: outcome.value || undefined,
            reason_group: reasonGroup.value || undefined,
            ...(allSelected || !isFilteringConnections.value
                ? {}
                : { connection_ids: selectedConnectionIds.value }),
            ...extra,
        },
        { preserveState: true, replace: true },
    );
}

function toggleConnection(id: number) {
    if (props.connections.length <= 1) return;
    const current = [...selectedConnectionIds.value];
    const idx = current.indexOf(id);
    if (idx >= 0) {
        if (current.length <= 1) return;
        current.splice(idx, 1);
    } else {
        current.push(id);
    }
    current.sort((a, b) => a - b);
    const allSelected = current.length === allConnectionIds.value.length;

    router.get(
        route('returns.items.index'),
        {
            period: period.value,
            q: q.value || undefined,
            outcome: outcome.value || undefined,
            reason_group: reasonGroup.value || undefined,
            ...(allSelected ? {} : { connection_ids: current }),
        },
        { preserveState: true, replace: true },
    );
}

function outcomeLabel(outcome: string | null) {
    switch (outcome) {
        case 'returned':
            return 'Devuelto';
        case 'refunded':
            return 'Reembolsado';
        case 'partial_refunded':
            return 'Parcial';
        default:
            return outcome || '—';
    }
}
</script>

<template>
    <Head title="Casos de devolución" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="mx-auto max-w-7xl space-y-3 px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Casos de devolución"
                    description="Listado individual con análisis de mensajes y drill-down."
                >
                    <template #actions>
                        <Link :href="route('returns.index')">
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-7 px-2.5 text-[11px]"
                            >
                                Dashboard
                            </Button>
                        </Link>
                    </template>
                </PageHeader>

                <div
                    v-if="connections.length > 1"
                    class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200/70 bg-white px-3 py-2 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                >
                    <span class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                        Conexiones
                    </span>
                    <button
                        v-for="c in connections"
                        :key="c.id"
                        type="button"
                        class="inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-[11px] font-medium transition-colors"
                        :style="isConnectionSelected(c.id) ? connectionSurfaceStyle(c.color) : undefined"
                        :class="
                            isConnectionSelected(c.id)
                                ? 'connection-chip border-[color:var(--conn-border)] bg-[color:var(--conn-chip)] text-[color:var(--conn-fg)]'
                                : 'border-slate-200 bg-slate-50 text-slate-500 hover:border-slate-300'
                        "
                        @click="toggleConnection(c.id)"
                    >
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: resolveConnectionColor(c.color) }"
                        />
                        {{ connectionFilterLabel(c) }}
                    </button>
                </div>

                <div class="flex flex-col gap-2 rounded-xl border border-slate-200/70 bg-white p-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)] md:flex-row md:items-center">
                    <select
                        v-model="period"
                        class="h-9 rounded-lg border border-slate-200 px-2.5 text-[12px]"
                        @change="apply()"
                    >
                        <option value="last_7_days">Últimos 7 días</option>
                        <option value="last_30_days">Últimos 30 días</option>
                        <option value="last_90_days">Últimos 90 días</option>
                        <option value="this_month">Este mes</option>
                        <option value="year_to_date">Año actual</option>
                    </select>
                    <Input
                        v-model="q"
                        class="h-9 flex-1 text-[12px]"
                        placeholder="Order ID, SKU, título, comentario…"
                        @keyup.enter="apply()"
                    />
                    <select
                        v-model="outcome"
                        class="h-9 rounded-lg border border-slate-200 px-2.5 text-[12px]"
                        @change="apply()"
                    >
                        <option value="">Outcome</option>
                        <option value="returned">Devuelto</option>
                        <option value="refunded">Reembolsado</option>
                        <option value="partial_refunded">Parcial</option>
                    </select>
                    <select
                        v-model="reasonGroup"
                        class="h-9 rounded-lg border border-slate-200 px-2.5 text-[12px]"
                        @change="apply()"
                    >
                        <option value="">Motivo</option>
                        <option value="defective">Defectuoso</option>
                        <option value="size">Talla</option>
                        <option value="not_as_expected">No esperado</option>
                        <option value="wrong_item">Equivocado</option>
                        <option value="logistics">Logística</option>
                        <option value="other">Otros</option>
                    </select>
                    <Button
                        size="sm"
                        class="h-9"
                        @click="apply()"
                    >
                        Filtrar
                    </Button>
                </div>

                <div class="overflow-hidden rounded-xl border border-slate-200/70 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50/80 text-left text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2.5">Fecha</th>
                                <th class="px-3 py-2.5">Conexión</th>
                                <th class="px-3 py-2.5">Order</th>
                                <th class="px-3 py-2.5">Producto</th>
                                <th class="px-3 py-2.5">SKU</th>
                                <th class="px-3 py-2.5">Importe</th>
                                <th class="px-3 py-2.5">Outcome</th>
                                <th class="px-3 py-2.5">Motivo</th>
                                <th class="px-3 py-2.5">Análisis</th>
                                <th class="px-3 py-2.5">Días</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in items.data"
                                :key="row.id"
                                class="border-t border-slate-100 hover:bg-slate-50/70"
                            >
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    <Link
                                        :href="route('returns.items.show', row.id)"
                                        class="text-[12px] font-medium text-brand hover:underline"
                                    >
                                        {{ formatDateTime(row.opened_at) }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2.5">
                                    <ConnectionChip
                                        v-if="row.connection"
                                        :connection="row.connection"
                                    />
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >—</span>
                                </td>
                                <td class="px-3 py-2.5 text-[12px] tabular-nums">
                                    <button
                                        v-if="row.order_id"
                                        type="button"
                                        class="font-medium text-brand hover:underline"
                                        @click="openOrder(row)"
                                    >
                                        {{ row.external_order_id || row.order_id }}
                                    </button>
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                    >—</span>
                                </td>
                                <td class="max-w-[200px] truncate px-3 py-2.5 text-[12px]">
                                    <button
                                        v-if="row.product_id || row.ml_item_id"
                                        type="button"
                                        class="truncate font-medium text-brand hover:underline"
                                        @click="openProduct(row)"
                                    >
                                        {{ row.product_name || 'Producto' }}
                                    </button>
                                    <span v-else>{{ row.product_name || '—' }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-[12px]">{{ row.sku || '—' }}</td>
                                <td class="px-3 py-2.5 tabular-nums">
                                    <MoneyText :amount="row.returned_amount" />
                                </td>
                                <td class="px-3 py-2.5">
                                    <Badge
                                        variant="outline"
                                        class="text-[10px]"
                                    >
                                        {{ outcomeLabel(row.outcome) }}
                                    </Badge>
                                </td>
                                <td class="px-3 py-2.5 text-[12px]">
                                    {{ row.reason_label || '—' }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <Badge
                                        variant="muted"
                                        class="text-[10px]"
                                    >
                                        {{ row.analysis_source || '—' }}
                                    </Badge>
                                </td>
                                <td class="px-3 py-2.5 tabular-nums">
                                    {{ row.days_to_return ?? '—' }}
                                </td>
                            </tr>
                            <tr v-if="!items.data?.length">
                                <td
                                    colspan="10"
                                    class="px-3 py-10 text-center text-sm text-muted-foreground"
                                >
                                    No hay casos en este periodo.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <OrderDetailSlideOver
            :show="orderSlideOpen"
            :order-id="selectedOrderId"
            :external-order-id="selectedExternalOrderId"
            :connection="selectedOrderConnection"
            initial-tab="claims"
            @close="closeOrder"
        />

        <ReturnProductSlideOver
            :show="productSlideOpen"
            :product-key="selectedProductKey"
            :period="period"
            :connection-ids="isFilteringConnections ? selectedConnectionIds : []"
            @close="closeProduct"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
.connection-chip {
    color: var(--conn-fg);
    background: var(--conn-chip);
    border-color: var(--conn-border);
}
</style>
