<script setup lang="ts">
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import FreshnessBadge from '@/Components/App/FreshnessBadge.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import OrderStatusPill from '@/Components/Domain/OrderStatusPill.vue';
import ProfitBreakdown, {
    type ProfitSnapshotLike,
} from '@/Components/Domain/ProfitBreakdown.vue';
import OrderCashReconciliation from '@/Components/Domain/OrderCashReconciliation.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';
import DataTable from '@/Components/App/DataTable.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import Button from '@/Components/ui/Button.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import { connectionFilterLabel } from '@/lib/connectionLabel';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

interface OrderLine {
    id: number;
    sku: string | null;
    title: string | null;
    quantity: string | number;
    unit_price_amount: string | number;
    line_total_amount: string | number;
    currency_code: string;
    match_status: string | null;
    external_item_id: string | null;
}

interface TimelineStep {
    key: string;
    label: string;
    at: string | null;
    done: boolean;
}

interface OrderShow {
    id: number;
    external_order_id: string | null;
    status: string;
    post_sale_outcome?: string | null;
    currency_code: string;
    total_amount: string | number;
    ordered_at: string | null;
    paid_at: string | null;
    buyer_external_id: string | null;
    meta?: Record<string, unknown> | null;
    lines: OrderLine[];
    profit_snapshots?: ProfitSnapshotLike[];
    connection?: {
        id: number;
        provider: string;
        external_user_id?: string | null;
        display_name?: string | null;
        color?: string | null;
    } | null;
}

const props = defineProps<{
    order: OrderShow;
    shipment: {
        id: number;
        status: string;
        tracking_number: string | null;
        carrier: string | null;
        shipped_at: string | null;
        delivered_at: string | null;
    } | null;
    timeline: TimelineStep[];
}>();

const page = usePage();
const syncing = ref(false);

const flashError = computed(() => {
    const flash = page.props.flash as { error?: string; success?: string } | undefined;
    return flash?.error ?? null;
});

const flashSuccess = computed(() => {
    const flash = page.props.flash as { error?: string; success?: string } | undefined;
    return flash?.success ?? null;
});

const expectedProfit = computed(() => {
    const snapshots = props.order.profit_snapshots ?? [];
    return (
        snapshots.find((s) => s.stage === 'expected') ??
        snapshots[0] ??
        null
    );
});

const freshness = computed(() => {
    const meta = props.order.meta ?? {};
    return (
        (meta.freshness_status as string | undefined) ??
        (meta.freshness as string | undefined) ??
        null
    );
});

const canSyncFromChannel = computed(
    () => props.order.connection?.provider === 'mercadolibre',
);

function syncNow() {
    if (syncing.value) return;
    syncing.value = true;
    router.post(
        route('orders.sync-now', props.order.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Orden #${order.id}`" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    :title="`Orden #${order.external_order_id ?? order.id}`"
                    :description="
                        order.connection
                            ? connectionFilterLabel(order.connection)
                            : 'Detalle operativo'
                    "
                >
                    <template #actions>
                        <Button
                            v-if="canSyncFromChannel"
                            type="button"
                            variant="outline"
                            :disabled="syncing"
                            @click="syncNow"
                        >
                            {{ syncing ? 'Actualizando…' : 'Actualizar' }}
                        </Button>
                        <Button as="a" variant="outline" :href="route('orders.index')">
                            Volver al inbox
                        </Button>
                        <Button
                            v-if="shipment"
                            as="a"
                            variant="outline"
                            :href="route('shipments.index')"
                        >
                            Ver envíos
                        </Button>
                    </template>
                </PageHeader>

                <p v-if="flashError" class="mb-4 text-sm text-red-600">{{ flashError }}</p>
                <p v-if="flashSuccess" class="mb-4 text-sm text-emerald-700">{{ flashSuccess }}</p>

                <Card class="mb-6">
                    <template #header>
                        <h3 class="text-sm font-semibold">Timeline operativo</h3>
                    </template>
                    <ol class="flex flex-wrap gap-4">
                        <li
                            v-for="step in timeline"
                            :key="step.key"
                            class="flex min-w-[8rem] flex-1 flex-col rounded-lg border px-3 py-2"
                            :class="
                                step.done
                                    ? 'border-emerald-200 bg-emerald-50'
                                    : 'border-slate-200 bg-slate-50'
                            "
                        >
                            <span class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                {{ step.label }}
                            </span>
                            <span class="mt-1 text-sm font-semibold text-slate-900">
                                {{ step.done ? 'Listo' : 'Pendiente' }}
                            </span>
                            <span class="mt-0.5 text-xs text-muted-foreground">
                                {{ step.at ?? '—' }}
                            </span>
                        </li>
                    </ol>
                </Card>

                <div class="mb-6 grid gap-4 lg:grid-cols-3">
                    <Card class="lg:col-span-2">
                        <template #header>
                            <h3 class="text-sm font-semibold">Resumen</h3>
                        </template>
                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-muted-foreground">Estado</dt>
                                <dd class="mt-1">
                                    <OrderStatusPill
                                        :status="order.status"
                                        :outcome="order.post_sale_outcome"
                                        show-fulfillment-hint
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Canal</dt>
                                <dd class="mt-1">
                                    <ConnectionChip
                                        v-if="order.connection"
                                        :connection="order.connection"
                                        :account-only="false"
                                        :compact="false"
                                    />
                                    <span v-else>—</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Freshness</dt>
                                <dd class="mt-1">
                                    <FreshnessBadge :status="freshness ?? 'unknown'" />
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Total</dt>
                                <dd class="mt-1">
                                    <MoneyText
                                        :amount="order.total_amount"
                                        :currency="order.currency_code"
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Comprador</dt>
                                <dd class="mt-1 font-mono text-sm">
                                    {{ order.buyer_external_id ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Envío</dt>
                                <dd class="mt-1 text-sm">
                                    <template v-if="shipment">
                                        <Badge variant="secondary" class="capitalize">
                                            {{ shipment.status }}
                                        </Badge>
                                        <span
                                            v-if="shipment.tracking_number"
                                            class="ml-2 font-mono text-xs"
                                        >
                                            {{ shipment.tracking_number }}
                                        </span>
                                    </template>
                                    <template v-else>—</template>
                                </dd>
                            </div>
                        </dl>
                    </Card>

                    <Card>
                        <template #header>
                            <h3 class="text-sm font-semibold">Acciones</h3>
                        </template>
                        <div class="space-y-2">
                            <Button
                                v-if="canSyncFromChannel"
                                type="button"
                                size="sm"
                                class="w-full"
                                :disabled="syncing"
                                @click="syncNow"
                            >
                                {{ syncing ? 'Actualizando…' : 'Actualizar desde ML' }}
                            </Button>
                            <Button as="a" size="sm" class="w-full" :href="route('shipments.index')">
                                Ir a envíos
                            </Button>
                            <Button as="a" size="sm" variant="outline" class="w-full" :href="route('claims.index')">
                                Reclamos
                            </Button>
                            <Link
                                :href="route('stock.index')"
                                class="block text-center text-sm font-medium text-brand hover:underline"
                            >
                                Ver stock
                            </Link>
                        </div>
                    </Card>
                </div>

                <div class="mb-6 space-y-4">
                    <ProfitBreakdown :snapshot="expectedProfit" />
                    <OrderCashReconciliation :order-id="order.id" />
                </div>

                <PageHeader title="Líneas" description="Ítems y estado de matching." />

                <DataTable
                    :is-empty="order.lines.length === 0"
                    empty-title="Sin líneas"
                >
                    <template #head>
                        <TableHead>SKU</TableHead>
                        <TableHead>Título</TableHead>
                        <TableHead>Qty</TableHead>
                        <TableHead>Unitario</TableHead>
                        <TableHead>Total</TableHead>
                        <TableHead>Match</TableHead>
                    </template>
                    <TableRow v-for="line in order.lines" :key="line.id">
                        <TableCell class="font-mono text-xs">
                            {{ line.sku ?? '—' }}
                        </TableCell>
                        <TableCell>{{ line.title ?? line.external_item_id ?? '—' }}</TableCell>
                        <TableCell>{{ line.quantity }}</TableCell>
                        <TableCell>
                            <MoneyText
                                :amount="line.unit_price_amount"
                                :currency="line.currency_code"
                            />
                        </TableCell>
                        <TableCell>
                            <MoneyText
                                :amount="line.line_total_amount"
                                :currency="line.currency_code"
                            />
                        </TableCell>
                        <TableCell>
                            <Badge variant="secondary" class="capitalize">
                                {{ line.match_status ?? 'unknown' }}
                            </Badge>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
