<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, router } from '@inertiajs/vue3';

interface DeadLetterRow {
    id: number;
    queue: string | null;
    job_class: string;
    error_redacted: string | null;
    failed_at: string | null;
    resolved_at: string | null;
}

interface PaginatedDeadLetters {
    data: DeadLetterRow[];
}

defineProps<{
    deadLetters: PaginatedDeadLetters;
}>();

const replay = (id: number) => {
    router.post(route('ops.dead-letters.replay', id));
};
</script>

<template>
    <Head title="Dead letters" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Dead letters"
                    description="Failed jobs awaiting replay or resolution."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="dead_letters"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                    </template>
                </PageHeader>

                <DataTable
                    :is-empty="deadLetters.data.length === 0"
                    empty-title="All clear"
                    empty-description="No unresolved dead letters in this workspace."
                >
                    <template #head>
                        <TableHead>ID</TableHead>
                        <TableHead>Job</TableHead>
                        <TableHead>Queue</TableHead>
                        <TableHead>Error</TableHead>
                        <TableHead>Failed</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead />
                    </template>
                    <TableRow v-for="item in deadLetters.data" :key="item.id">
                        <TableCell>{{ item.id }}</TableCell>
                        <TableCell class="font-mono text-xs">
                            {{ item.job_class }}
                        </TableCell>
                        <TableCell>{{ item.queue ?? '—' }}</TableCell>
                        <TableCell class="max-w-xs truncate text-xs text-muted-foreground">
                            {{ item.error_redacted ?? '—' }}
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ item.failed_at ?? '—' }}
                        </TableCell>
                        <TableCell>
                            <Badge :variant="item.resolved_at ? 'success' : 'warning'">
                                {{ item.resolved_at ? 'Resolved' : 'Open' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                v-if="!item.resolved_at"
                                size="sm"
                                variant="outline"
                                @click="replay(item.id)"
                            >
                                Replay
                            </Button>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
