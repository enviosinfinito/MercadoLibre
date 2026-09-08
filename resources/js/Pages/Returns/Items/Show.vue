<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import OrderDetailSlideOver from '@/Components/Orders/OrderDetailSlideOver.vue';
import ReturnProductSlideOver from '@/Components/Returns/ReturnProductSlideOver.vue';
import FullOperationDetailSlideOver from '@/Components/Inventory/FullOperationDetailSlideOver.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDateTime } from '@/lib/utils';
import { ArrowLeft, Package } from 'lucide-vue-next';

const props = defineProps<{
    item: Record<string, any>;
}>();

const orderSlideOpen = ref(false);
const productSlideOpen = ref(false);
const fullOpSlideOpen = ref(false);
const selectedFullOpId = ref<number | null>(null);

function outcomeLabel(outcome: string | null) {
    switch (outcome) {
        case 'returned':
            return 'Devuelto';
        case 'refunded':
            return 'Reembolsado';
        case 'partial_refunded':
            return 'Parcial';
        case 'claim_open':
            return 'Reclamo abierto';
        default:
            return outcome || '—';
    }
}

const productKey = computed(() => {
    const p = props.item.product;
    if (p?.id) return String(p.id);
    const ml = props.item.items?.[0]?.ml_item_id || props.item.order?.lines?.[0]?.ml_item_id;
    return ml ? `ml:${ml}` : null;
});

const orderId = computed(() => {
    const id = props.item.order?.id ?? props.item.order_id;
    return id != null ? Number(id) : null;
});

const fullOperations = computed(() => props.item.full_operations ?? []);

function openOrder() {
    if (!orderId.value) return;
    orderSlideOpen.value = true;
}

function openProduct() {
    if (!productKey.value) return;
    productSlideOpen.value = true;
}

function openFullOp(id: number) {
    selectedFullOpId.value = id;
    fullOpSlideOpen.value = true;
}

function closeFullOp() {
    fullOpSlideOpen.value = false;
    selectedFullOpId.value = null;
}

function fmtDelta(v: string | null | undefined) {
    if (v == null || v === '') return '—';
    const n = Number(v);
    if (Number.isNaN(n)) return v;
    const formatted = n.toLocaleString('es-MX', { maximumFractionDigits: 2 });
    return n > 0 ? `+${formatted}` : formatted;
}
</script>

<template>
    <Head title="Detalle de devolución" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="mx-auto max-w-5xl space-y-3 px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Detalle de devolución"
                    :description="`Abierta ${formatDateTime(item.opened_at)}`"
                >
                    <template #actions>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Link :href="route('returns.items.index')">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-7 px-2.5 text-[11px]"
                                >
                                    <ArrowLeft class="mr-1 size-3.5" />
                                    Casos
                                </Button>
                            </Link>
                            <Button
                                v-if="orderId"
                                variant="outline"
                                size="sm"
                                class="h-7 px-2.5 text-[11px]"
                                @click="openOrder"
                            >
                                <Package class="mr-1 size-3.5" />
                                Ver orden
                            </Button>
                            <Button
                                v-if="productKey"
                                variant="outline"
                                size="sm"
                                class="h-7 px-2.5 text-[11px]"
                                @click="openProduct"
                            >
                                Análisis producto
                            </Button>
                        </div>
                    </template>
                </PageHeader>

                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-if="orderId"
                        type="button"
                        class="inline-flex h-6 items-center rounded-md border border-slate-200 bg-white px-2 text-[11px] font-semibold tabular-nums text-brand hover:bg-slate-50"
                        @click="openOrder"
                    >
                        Orden #{{ item.order?.external_order_id || orderId }}
                    </button>
                    <Badge class="text-[10px]">
                        {{ outcomeLabel(item.outcome) }}
                    </Badge>
                    <Badge
                        variant="outline"
                        class="text-[10px]"
                    >
                        {{ item.status || '—' }}
                    </Badge>
                    <Badge
                        variant="muted"
                        class="text-[10px]"
                    >
                        {{ item.analysis_source || 'sin análisis' }}
                    </Badge>
                    <Badge
                        v-if="item.analysis_confidence"
                        variant="outline"
                        class="text-[10px]"
                    >
                        Confianza {{ item.analysis_confidence }}
                    </Badge>
                    <ConnectionChip
                        v-if="item.connection"
                        :connection="item.connection"
                    />
                </div>

                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Importe
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText :amount="item.returned_amount" />
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Pérdida estimada
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            <MoneyText :amount="item.estimated_loss" />
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Días hasta devolución
                        </p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                            {{ item.days_to_return ?? '—' }}
                        </p>
                    </Card>
                    <Card
                        class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                        content-class="p-3"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Fechas
                        </p>
                        <p class="mt-1 text-[11px] text-slate-700">
                            Compra: {{ formatDateTime(item.ordered_at) }}
                        </p>
                        <p class="text-[11px] text-slate-700">
                            Entrega: {{ formatDateTime(item.delivered_at) }}
                        </p>
                        <p class="text-[11px] text-slate-700">
                            Devolución: {{ formatDateTime(item.opened_at) }}
                        </p>
                    </Card>
                </div>

                <Card
                    class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                    content-class="space-y-2 p-3"
                >
                    <h2 class="text-[13px] font-semibold tracking-tight text-slate-900">
                        Análisis
                    </h2>
                    <p class="text-[13px] leading-relaxed text-slate-700">
                        {{ item.analysis_summary || 'Sin resumen todavía.' }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        Motivo: {{ item.reason_label || '—' }}
                        <span v-if="item.reason_group"> · {{ item.reason_group }}</span>
                    </p>
                    <p
                        v-if="item.buyer_comment"
                        class="rounded-lg border border-slate-100 bg-slate-50/60 px-3 py-2 text-[12px] italic text-slate-700"
                    >
                        “{{ item.buyer_comment }}”
                    </p>
                    <div
                        v-if="item.message_analysis?.evidence?.quotes?.length"
                        class="space-y-1 border-t border-slate-100 pt-2"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            Evidencias
                        </p>
                        <p
                            v-for="(q, idx) in item.message_analysis.evidence.quotes"
                            :key="idx"
                            class="text-[11px] text-slate-600"
                        >
                            • {{ q }}
                        </p>
                    </div>
                </Card>

                <Card
                    v-if="item.financial_impact"
                    class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                    content-class="space-y-2 p-3"
                >
                    <h3 class="text-[13px] font-semibold tracking-tight">
                        Impacto financiero
                    </h3>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="rounded-lg border border-slate-100 bg-slate-50/50 px-3 py-2 text-[12px]">
                            Venta: <MoneyText :amount="item.financial_impact.sale_amount" />
                        </div>
                        <div class="rounded-lg border border-slate-100 bg-slate-50/50 px-3 py-2 text-[12px]">
                            Reembolso: <MoneyText :amount="item.financial_impact.refund_amount" />
                        </div>
                        <div class="rounded-lg border border-slate-100 bg-slate-50/50 px-3 py-2 text-[12px]">
                            Envío: <MoneyText :amount="item.financial_impact.shipping_cost" />
                        </div>
                        <div class="rounded-lg border border-slate-100 bg-slate-50/50 px-3 py-2 text-[12px]">
                            Costo producto: <MoneyText :amount="item.financial_impact.product_cost" />
                        </div>
                    </div>
                    <p class="text-[13px] font-semibold text-slate-900">
                        Pérdida total:
                        <MoneyText :amount="item.financial_impact.estimated_total_loss" />
                    </p>
                </Card>

                <Card
                    class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                    content-class="p-0"
                >
                    <div class="border-b border-slate-100 px-3 py-2.5">
                        <h3 class="text-[13px] font-semibold tracking-tight">
                            Ítems
                        </h3>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="line in item.items || []"
                            :key="line.id"
                            class="px-3 py-2.5"
                        >
                            <p class="text-[13px] font-medium text-slate-900">
                                {{ line.title }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                {{ line.sku || 'sin SKU' }}
                                · {{ line.variant_label || 'sin variante' }}
                                · qty {{ line.quantity }}
                            </p>
                        </div>
                        <p
                            v-if="!(item.items || []).length"
                            class="px-3 py-8 text-center text-sm text-muted-foreground"
                        >
                            Sin líneas asociadas.
                        </p>
                    </div>
                </Card>

                <Card
                    class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                    content-class="p-0"
                >
                    <div class="border-b border-slate-100 px-3 py-2.5">
                        <h3 class="text-[13px] font-semibold tracking-tight">
                            Movimientos Full relacionados
                        </h3>
                        <p class="text-[11px] text-muted-foreground">
                            Enlazados por id de orden/envío en las referencias de stock Full (ML no siempre usa SALE_RETURN).
                        </p>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <button
                            v-for="op in fullOperations"
                            :key="op.id"
                            type="button"
                            class="flex w-full items-start justify-between gap-3 px-3 py-2.5 text-left hover:bg-slate-50"
                            @click="openFullOp(op.id)"
                        >
                            <div>
                                <p class="text-[13px] font-medium text-slate-900">
                                    {{ op.operation_type_label }}
                                </p>
                                <p class="font-mono text-[10px] text-muted-foreground">
                                    {{ op.operation_type }}
                                    <span v-if="op.inventory_id"> · inv {{ op.inventory_id }}</span>
                                </p>
                                <p class="mt-0.5 text-[11px] text-muted-foreground">
                                    {{ formatDateTime(op.occurred_at) }}
                                </p>
                            </div>
                            <div class="text-right font-mono text-[11px] text-slate-700">
                                <div>Δ disp. {{ fmtDelta(op.available_quantity_delta) }}</div>
                                <div class="text-muted-foreground">
                                    Δ no {{ fmtDelta(op.not_available_quantity_delta) }}
                                </div>
                            </div>
                        </button>
                        <p
                            v-if="!fullOperations.length"
                            class="px-3 py-8 text-center text-sm text-muted-foreground"
                        >
                            Sin movimientos Full enlazados todavía.
                        </p>
                    </div>
                </Card>

                <Card
                    v-if="item.claim?.messages?.length"
                    class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                    content-class="space-y-2 p-3"
                >
                    <h3 class="text-[13px] font-semibold tracking-tight">
                        Mensajes del reclamo
                    </h3>
                    <div
                        v-for="(m, idx) in item.claim.messages"
                        :key="m.id || idx"
                        class="rounded-lg border border-slate-100 bg-slate-50/40 px-3 py-2"
                    >
                        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            {{ m.sender_role || m.from || 'mensaje' }}
                            <span v-if="m.created_at"> · {{ formatDateTime(m.created_at) }}</span>
                        </p>
                        <p class="mt-0.5 text-[12px] text-slate-700">
                            {{ m.message || m.text || m.body || '—' }}
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <OrderDetailSlideOver
            :show="orderSlideOpen"
            :order-id="orderId"
            :external-order-id="item.order?.external_order_id ?? orderId"
            :connection="item.connection"
            initial-tab="claims"
            @close="orderSlideOpen = false"
        />

        <ReturnProductSlideOver
            :show="productSlideOpen"
            :product-key="productKey"
            @close="productSlideOpen = false"
        />

        <FullOperationDetailSlideOver
            :show="fullOpSlideOpen"
            :operation-id="selectedFullOpId"
            @close="closeFullOp"
        />
    </AuthenticatedLayout>
</template>
