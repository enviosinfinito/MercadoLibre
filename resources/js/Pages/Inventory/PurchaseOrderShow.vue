<script setup lang="ts">
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Input from '@/Components/ui/Input.vue';
import StockDetailSlideOver from '@/Components/Inventory/StockDetailSlideOver.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { formatDateTime } from '@/lib/utils';

interface Line {
    id: number;
    variant_id: number;
    qty_ordered: string;
    qty_received: string;
    variance: string;
    unit_cost_amount: string;
    currency: string;
    variant: { id: number; sku: string; name: string | null } | null;
}

const props = defineProps<{
    purchase_order: {
        id: number;
        supplier_name: string;
        status: string;
        ordered_at: string | null;
        notes: string | null;
        lines: Line[];
    };
    warehouses: Array<{ id: number; code: string; name: string; is_default: boolean }>;
}>();

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as { success?: string } | undefined)?.success,
);

const selectedVariantId = ref<number | null>(null);
const receivingLineId = ref<number | null>(null);
const defaultWarehouseId = computed(
    () => props.warehouses.find((w) => w.is_default)?.id ?? props.warehouses[0]?.id ?? null,
);

const receiveForm = useForm({
    quantity: '',
    warehouse_id: defaultWarehouseId.value as number | null,
    notes: '',
});

function startReceive(line: Line) {
    receivingLineId.value = line.id;
    receiveForm.quantity = String(Number(line.qty_ordered) - Number(line.qty_received) || line.qty_ordered);
    receiveForm.warehouse_id = defaultWarehouseId.value;
    receiveForm.notes = '';
}

function submitReceive(line: Line) {
    receiveForm.post(route('inventory.purchase-orders.receive', [props.purchase_order.id, line.id]), {
        preserveScroll: true,
        onSuccess: () => {
            receivingLineId.value = null;
            receiveForm.reset();
        },
    });
}

function fmtQty(v: string | number | null | undefined) {
    if (v == null || v === '') return '—';
    return Number(v).toLocaleString('es-MX', { maximumFractionDigits: 2 });
}

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
</script>

<template>
    <Head :title="`OC #${purchase_order.id}`" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    :title="`Orden de compra #${purchase_order.id}`"
                    :description="purchase_order.supplier_name"
                >
                    <template #actions>
                        <Link
                            :href="route('inventory.receipts.index')"
                            class="text-sm text-brand hover:underline"
                        >
                            Volver a Ingresos
                        </Link>
                    </template>
                </PageHeader>

                <p
                    v-if="flashSuccess"
                    class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                >
                    {{ flashSuccess }}
                </p>

                <Card class="mb-6">
                    <dl class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-muted-foreground">Estado</dt>
                            <dd class="mt-1">
                                <Badge variant="secondary">{{ statusLabel(purchase_order.status) }}</Badge>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Fecha</dt>
                            <dd class="mt-1 text-sm">{{ formatDateTime(purchase_order.ordered_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Notas</dt>
                            <dd class="mt-1 text-sm">{{ purchase_order.notes || '—' }}</dd>
                        </div>
                    </dl>
                </Card>

                <div class="space-y-4">
                    <article
                        v-for="line in purchase_order.lines"
                        :key="line.id"
                        class="rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <button
                                type="button"
                                class="text-left"
                                @click="selectedVariantId = line.variant_id"
                            >
                                <p class="font-mono text-sm font-semibold text-brand hover:underline">
                                    {{ line.variant?.sku ?? '—' }}
                                </p>
                                <p class="text-xs text-muted-foreground">{{ line.variant?.name ?? '' }}</p>
                            </button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="startReceive(line)"
                            >
                                Recibir
                            </Button>
                        </div>
                        <dl class="mt-3 grid gap-2 sm:grid-cols-4 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">Pedidas</dt>
                                <dd>{{ fmtQty(line.qty_ordered) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Recibidas</dt>
                                <dd>{{ fmtQty(line.qty_received) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Variación</dt>
                                <dd class="font-mono">{{ fmtQty(line.variance) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Costo</dt>
                                <dd>{{ line.unit_cost_amount }} {{ line.currency }}</dd>
                            </div>
                        </dl>

                        <div
                            v-if="receivingLineId === line.id"
                            class="mt-3 space-y-2 rounded-md border border-slate-200 p-3"
                        >
                            <p class="text-xs text-muted-foreground">
                                Esperadas {{ fmtQty(line.qty_ordered) }}. Ingresa la cantidad real.
                            </p>
                            <select
                                v-model="receiveForm.warehouse_id"
                                class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
                            >
                                <option
                                    v-for="w in warehouses"
                                    :key="w.id"
                                    :value="w.id"
                                >
                                    {{ w.code }} — {{ w.name }}
                                </option>
                            </select>
                            <Input
                                v-model="receiveForm.quantity"
                                type="number"
                                min="0"
                                step="any"
                                placeholder="Cantidad real"
                            />
                            <Input v-model="receiveForm.notes" placeholder="Notas" />
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="receiveForm.processing"
                                    @click="submitReceive(line)"
                                >
                                    Confirmar recepción
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="receivingLineId = null"
                                >
                                    Cancelar
                                </Button>
                            </div>
                        </div>
                    </article>
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
