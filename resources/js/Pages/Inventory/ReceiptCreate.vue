<script setup lang="ts">
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Input from '@/Components/ui/Input.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

interface VariantOption {
    id: number;
    sku: string;
    name: string | null;
}

interface WarehouseOption {
    id: number;
    code: string;
    name: string;
    is_default: boolean;
}

const props = defineProps<{
    variants: VariantOption[];
    warehouses: WarehouseOption[];
}>();

const defaultWarehouseId = computed(
    () =>
        props.warehouses.find((w) => w.is_default)?.id ??
        props.warehouses[0]?.id ??
        null,
);

const form = useForm({
    variant_id: (props.variants[0]?.id ?? null) as number | null,
    warehouse_id: defaultWarehouseId.value as number | null,
    quantity: '1',
    unit_cost_amount: '0',
    unit_cost_currency: 'MXN',
    fx_rate: '1',
    reporting_currency: 'MXN',
    notes: '',
});

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as { success?: string } | undefined)?.success,
);

const submit = () => {
    form.post(route('inventory.receipts.store'));
};
</script>

<template>
    <Head title="Recibir inventario" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Recibir inventario"
                    description="Recibo suelto (sin orden de compra). Crea una capa de costo."
                >
                    <template #actions>
                        <Link
                            :href="route('inventory.purchase-orders.create')"
                            class="text-sm text-brand hover:underline"
                        >
                            Mejor crear una OC
                        </Link>
                    </template>
                </PageHeader>

                <p
                    v-if="flashSuccess"
                    class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                >
                    {{ flashSuccess }}
                </p>

                <Card>
                    <form class="space-y-4" @submit.prevent="submit">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Variante</label>
                            <select
                                v-model="form.variant_id"
                                class="flex h-10 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                required
                            >
                                <option
                                    v-for="v in variants"
                                    :key="v.id"
                                    :value="v.id"
                                >
                                    {{ v.sku }} — {{ v.name ?? 'Default' }}
                                </option>
                            </select>
                            <p v-if="form.errors.variant_id" class="mt-1 text-xs text-destructive">
                                {{ form.errors.variant_id }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">Almacén</label>
                            <select
                                v-model="form.warehouse_id"
                                class="flex h-10 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option
                                    v-for="w in warehouses"
                                    :key="w.id"
                                    :value="w.id"
                                >
                                    {{ w.code }} — {{ w.name }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Cantidad real</label>
                                <Input
                                    v-model="form.quantity"
                                    type="number"
                                    step="0.000001"
                                    min="0"
                                    required
                                />
                                <p
                                    v-if="form.errors.quantity"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ form.errors.quantity }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Costo unitario</label>
                                <Input
                                    v-model="form.unit_cost_amount"
                                    type="number"
                                    step="0.000001"
                                    min="0"
                                    required
                                />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Moneda</label>
                                <Input
                                    v-model="form.unit_cost_currency"
                                    maxlength="3"
                                    required
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Tipo de cambio</label>
                                <Input
                                    v-model="form.fx_rate"
                                    type="number"
                                    step="0.000001"
                                    min="0"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">
                                    Moneda de reporte
                                </label>
                                <Input v-model="form.reporting_currency" maxlength="3" />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">Notas</label>
                            <Input v-model="form.notes" placeholder="Notas opcionales" />
                        </div>

                        <div class="flex justify-end pt-2">
                            <Button type="submit" :disabled="form.processing">
                                {{ form.processing ? 'Guardando…' : 'Recibir inventario' }}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
