<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import UsersSearchAndFilters from '@/Components/Admin/UsersSearchAndFilters.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, Link } from '@inertiajs/vue3';

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_platform_admin: boolean;
    memberships_count: number;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    users: Paginated<UserRow>;
    filters: { search: string };
}>();

</script>

<template>
    <Head title="Admin · Users" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Admin · Users"
                    description="Manage all platform users."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="admin_users"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                        <Button as="a" :href="route('admin.users.create')">New user</Button>
                    </template>
                </PageHeader>

                <UsersSearchAndFilters :filters="filters" />

                <DataTable
                    :is-empty="users.data.length === 0"
                    empty-title="No users"
                    empty-description="Create a user to get started."
                >
                    <template #head>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Memberships</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead />
                    </template>
                    <TableRow v-for="u in users.data" :key="u.id">
                        <TableCell class="font-medium">{{ u.name }}</TableCell>
                        <TableCell>{{ u.email }}</TableCell>
                        <TableCell>{{ u.memberships_count }}</TableCell>
                        <TableCell>
                            <Badge v-if="u.is_platform_admin" variant="warning">
                                Platform admin
                            </Badge>
                            <Badge v-else variant="muted">User</Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                as="a"
                                :href="route('admin.users.show', u.id)"
                            >
                                View
                            </Button>
                        </TableCell>
                    </TableRow>
                    <template #footer>
                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="(link, i) in users.links"
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
