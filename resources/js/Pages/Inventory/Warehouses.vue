<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Input from '@/Components/ui/Input.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface WarehouseRow {
    id: number;
    code: string;
    name: string;
    is_default: boolean;
    is_active: boolean;
}

const props = defineProps<{
    warehouses: WarehouseRow[];
}>();

const createForm = useForm({
    code: '',
    name: '',
    is_default: false,
    is_active: true,
});

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as { success?: string } | undefined)?.success,
);

function submitCreate() {
    createForm.post(route('inventory.warehouses.store'), {
        onSuccess: () => createForm.reset(),
    });
}

function toggleActive(w: WarehouseRow) {
    useForm({ is_active: !w.is_active }).put(route('inventory.warehouses.update', w.id), {
        preserveScroll: true,
    });
}

function setDefault(w: WarehouseRow) {
    useForm({ is_default: true }).put(route('inventory.warehouses.update', w.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Almacenes" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Almacenes"
                    description="Depósitos internos del workspace."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="warehouses"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                        <Link
                            :href="route('stock.index')"
                            class="text-sm text-brand hover:underline"
                        >
                            Volver a Stock
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
                    <form
                        class="grid gap-3 sm:grid-cols-4"
                        @submit.prevent="submitCreate"
                    >
                        <Input
                            v-model="createForm.code"
                            placeholder="Código"
                            required
                        />
                        <Input
                            v-model="createForm.name"
                            placeholder="Nombre"
                            required
                            class="sm:col-span-2"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="createForm.processing"
                        >
                            Crear
                        </Button>
                    </form>
                </Card>

                <DataTable
                    :is-empty="warehouses.length === 0"
                    empty-title="Sin almacenes"
                    empty-description="Crea el primero arriba."
                >
                    <template #head>
                        <TableHead>Código</TableHead>
                        <TableHead>Nombre</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead></TableHead>
                    </template>
                    <TableRow
                        v-for="w in warehouses"
                        :key="w.id"
                    >
                        <TableCell class="font-mono text-xs">{{ w.code }}</TableCell>
                        <TableCell>{{ w.name }}</TableCell>
                        <TableCell>
                            <Badge
                                v-if="w.is_default"
                                class="mr-1"
                            >
                                Default
                            </Badge>
                            <Badge :variant="w.is_active ? 'success' : 'muted'">
                                {{ w.is_active ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="space-x-2 text-right">
                            <Button
                                v-if="!w.is_default"
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="setDefault(w)"
                            >
                                Hacer default
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="toggleActive(w)"
                            >
                                {{ w.is_active ? 'Desactivar' : 'Activar' }}
                            </Button>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
