<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Input from '@/Components/ui/Input.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface VariantOption {
    id: number;
    sku: string;
    name: string | null;
}

const props = defineProps<{
    variants: VariantOption[];
}>();

const form = useForm({
    supplier_name: '',
    notes: '',
    ordered_at: '',
    lines: [
        {
            variant_id: (props.variants[0]?.id ?? null) as number | null,
            qty_ordered: '1000',
            unit_cost_amount: '0',
            currency: 'MXN',
        },
    ],
});

function addLine() {
    form.lines.push({
        variant_id: props.variants[0]?.id ?? null,
        qty_ordered: '1',
        unit_cost_amount: '0',
        currency: 'MXN',
    });
}

function removeLine(index: number) {
    if (form.lines.length === 1) return;
    form.lines.splice(index, 1);
}

const submit = () => {
    form.post(route('inventory.purchase-orders.store'));
};
</script>

<template>
    <Head title="Nueva orden de compra" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Nueva orden de compra"
                    description="Registra lo pedido al proveedor. La recepción real puede diferir."
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

                <Card>
                    <form class="space-y-4" @submit.prevent="submit">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Proveedor</label>
                            <Input
                                v-model="form.supplier_name"
                                required
                                placeholder="Nombre del proveedor"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Fecha de pedido</label>
                            <Input v-model="form.ordered_at" type="date" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Notas</label>
                            <Input v-model="form.notes" placeholder="Opcional" />
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold">Líneas</h3>
                                <Button type="button" size="sm" variant="outline" @click="addLine">
                                    Agregar línea
                                </Button>
                            </div>
                            <div
                                v-for="(line, index) in form.lines"
                                :key="index"
                                class="space-y-2 rounded-md border border-slate-200 p-3"
                            >
                                <select
                                    v-model="line.variant_id"
                                    class="flex h-10 w-full rounded-md border border-input bg-white px-3 text-sm"
                                    required
                                >
                                    <option
                                        v-for="v in variants"
                                        :key="v.id"
                                        :value="v.id"
                                    >
                                        {{ v.sku }} — {{ v.name ?? '—' }}
                                    </option>
                                </select>
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <Input
                                        v-model="line.qty_ordered"
                                        type="number"
                                        min="0"
                                        step="any"
                                        placeholder="Cantidad pedida"
                                        required
                                    />
                                    <Input
                                        v-model="line.unit_cost_amount"
                                        type="number"
                                        min="0"
                                        step="any"
                                        placeholder="Costo unit."
                                        required
                                    />
                                    <Input
                                        v-model="line.currency"
                                        maxlength="3"
                                        required
                                    />
                                </div>
                                <button
                                    v-if="form.lines.length > 1"
                                    type="button"
                                    class="text-xs text-rose-600 hover:underline"
                                    @click="removeLine(index)"
                                >
                                    Quitar línea
                                </button>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2">
                            <Button type="submit" :disabled="form.processing">
                                {{ form.processing ? 'Guardando…' : 'Crear orden de compra' }}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
