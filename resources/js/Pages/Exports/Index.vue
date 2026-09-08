<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import DataTable from '@/Components/App/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import Card from '@/Components/ui/Card.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface ExportRow {
    id: number;
    report_type: string;
    format: string;
    status: string;
    row_count: number | null;
    created_at?: string;
    finished_at: string | null;
    error_redacted: string | null;
    storage_path?: string | null;
}

interface PaginatedExports {
    data: ExportRow[];
}

defineProps<{
    exports: PaginatedExports;
}>();

const form = useForm({
    report_type: 'orders',
    format: 'csv' as 'csv' | 'xlsx',
});

const submit = () => {
    form.post(route('exports.store'), {
        onSuccess: () => form.reset('report_type'),
    });
};

const statusVariant = (status: string) => {
    switch (status) {
        case 'completed':
            return 'success' as const;
        case 'failed':
            return 'danger' as const;
        case 'running':
            return 'warning' as const;
        default:
            return 'secondary' as const;
    }
};
</script>

<template>
    <Head title="Exports" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Exports"
                    description="Queue report exports for download."
                />

                <Card class="mb-6">
                    <form
                        class="flex flex-col gap-3 sm:flex-row sm:items-end"
                        @submit.prevent="submit"
                    >
                        <div class="flex-1">
                            <label class="mb-1 block text-sm font-medium">Report type</label>
                            <Input v-model="form.report_type" required />
                        </div>
                        <div class="w-full sm:w-40">
                            <label class="mb-1 block text-sm font-medium">Format</label>
                            <select
                                v-model="form.format"
                                class="flex h-10 w-full rounded-md border border-input bg-white px-3 text-sm"
                            >
                                <option value="csv">CSV</option>
                                <option value="xlsx">XLSX</option>
                            </select>
                        </div>
                        <Button type="submit" :disabled="form.processing">
                            Queue export
                        </Button>
                    </form>
                </Card>

                <DataTable
                    :is-empty="exports.data.length === 0"
                    empty-title="No exports yet"
                    empty-description="Queue your first report export above."
                >
                    <template #head>
                        <TableHead>ID</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Format</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Rows</TableHead>
                        <TableHead>Finished</TableHead>
                        <TableHead></TableHead>
                    </template>
                    <TableRow v-for="run in exports.data" :key="run.id">
                        <TableCell>{{ run.id }}</TableCell>
                        <TableCell>{{ run.report_type }}</TableCell>
                        <TableCell class="uppercase">{{ run.format }}</TableCell>
                        <TableCell>
                            <Badge :variant="statusVariant(run.status)" class="capitalize">
                                {{ run.status }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ run.row_count ?? '—' }}</TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ run.finished_at ?? '—' }}
                        </TableCell>
                        <TableCell>
                            <Button
                                v-if="run.status === 'completed'"
                                as="a"
                                size="sm"
                                variant="outline"
                                :href="route('exports.download', run.id)"
                            >
                                Download
                            </Button>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
