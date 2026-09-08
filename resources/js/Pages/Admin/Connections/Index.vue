<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import ConnectionHealth from '@/Components/Domain/ConnectionHealth.vue';
import ConnectionsSearchAndFilters from '@/Components/Admin/ConnectionsSearchAndFilters.vue';
import Button from '@/Components/ui/Button.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, Link } from '@inertiajs/vue3';

interface ConnectionRow {
    id: number;
    provider: string;
    external_user_id: string | null;
    site_id: string | null;
    status: string;
    needs_reauthorization: boolean;
    freshness_status: string | null;
    last_synced_at: string | null;
    workspace?: { id: number; name: string; slug: string } | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    connections: Paginated<ConnectionRow>;
    filters: { search: string; provider: string; status: string };
}>();

</script>

<template>
    <Head title="Admin · Connections" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Admin · Connections"
                    description="All marketplace connections across workspaces."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="admin_connections"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                    </template>
                </PageHeader>

                <ConnectionsSearchAndFilters :filters="filters" />

                <DataTable
                    :is-empty="connections.data.length === 0"
                    empty-title="No connections"
                    empty-description="No marketplace connections found."
                >
                    <template #head>
                        <TableHead>Workspace</TableHead>
                        <TableHead>Provider</TableHead>
                        <TableHead>External user</TableHead>
                        <TableHead>Site</TableHead>
                        <TableHead>Health</TableHead>
                        <TableHead />
                    </template>
                    <TableRow v-for="c in connections.data" :key="c.id">
                        <TableCell class="font-medium">
                            {{ c.workspace?.name ?? '—' }}
                        </TableCell>
                        <TableCell class="capitalize">{{ c.provider }}</TableCell>
                        <TableCell class="font-mono text-xs">
                            {{ c.external_user_id ?? '—' }}
                        </TableCell>
                        <TableCell>{{ c.site_id ?? '—' }}</TableCell>
                        <TableCell>
                            <ConnectionHealth
                                :status="c.status"
                                :freshness-status="c.freshness_status"
                                :needs-reauthorization="c.needs_reauthorization"
                                :last-synced-at="c.last_synced_at"
                            />
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                as="a"
                                :href="route('admin.connections.show', c.id)"
                            >
                                View
                            </Button>
                        </TableCell>
                    </TableRow>
                    <template #footer>
                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="(link, i) in connections.links"
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
