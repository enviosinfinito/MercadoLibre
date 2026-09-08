<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import WorkspacesSearchAndFilters from '@/Components/Admin/WorkspacesSearchAndFilters.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, Link, router } from '@inertiajs/vue3';

interface WorkspaceRow {
    id: number;
    name: string;
    slug: string;
    reporting_currency: string;
    default_costing_method: string;
    memberships_count: number;
    connections_count: number;
    deleted_at: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    workspaces: Paginated<WorkspaceRow>;
    filters: { search: string };
}>();

const restore = (id: number) => {
    router.post(route('admin.workspaces.restore', id));
};
</script>

<template>
    <Head title="Admin · Workspaces" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Admin · Workspaces"
                    description="Manage all platform workspaces."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="admin_workspaces"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                        <Button as="a" :href="route('admin.workspaces.create')">
                            New workspace
                        </Button>
                    </template>
                </PageHeader>

                <WorkspacesSearchAndFilters :filters="filters" />

                <DataTable
                    :is-empty="workspaces.data.length === 0"
                    empty-title="No workspaces"
                    empty-description="Create a workspace to get started."
                >
                    <template #head>
                        <TableHead>Name</TableHead>
                        <TableHead>Slug</TableHead>
                        <TableHead>Currency</TableHead>
                        <TableHead>Members</TableHead>
                        <TableHead>Connections</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead />
                    </template>
                    <TableRow v-for="ws in workspaces.data" :key="ws.id">
                        <TableCell class="font-medium">{{ ws.name }}</TableCell>
                        <TableCell class="font-mono text-xs">{{ ws.slug }}</TableCell>
                        <TableCell>{{ ws.reporting_currency }}</TableCell>
                        <TableCell>{{ ws.memberships_count }}</TableCell>
                        <TableCell>{{ ws.connections_count }}</TableCell>
                        <TableCell>
                            <Badge v-if="ws.deleted_at" variant="danger">Archived</Badge>
                            <Badge v-else variant="success">Active</Badge>
                        </TableCell>
                        <TableCell class="space-x-2 text-right">
                            <template v-if="ws.deleted_at">
                                <Button size="sm" variant="outline" @click="restore(ws.id)">
                                    Restore
                                </Button>
                            </template>
                            <template v-else>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    as="a"
                                    :href="route('admin.workspaces.show', ws.id)"
                                >
                                    View
                                </Button>
                            </template>
                        </TableCell>
                    </TableRow>
                    <template #footer>
                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="(link, i) in workspaces.links"
                                :key="i"
                                :href="link.url ?? '#'"
                                class="rounded px-2 py-1 text-xs"
                                :class="
                                    link.active
                                        ? 'bg-brand text-white'
                                        : link.url
                                          ? 'text-slate-600 hover:bg-slate-100'
                                          : 'pointer-events-none text-slate-300'
                                "
                                v-html="link.label"
                            />
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
