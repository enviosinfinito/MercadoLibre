<script setup lang="ts">
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import DataTable from '@/Components/App/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, router } from '@inertiajs/vue3';

interface MemberRow {
    id: number;
    role_name: string;
    user?: { id: number; name: string; email: string } | null;
}

defineProps<{
    members: MemberRow[];
}>();

const email = ref('');
const name = ref('');
const role = ref('member');

const invite = () => {
    if (!email.value.trim()) {
        return;
    }
    router.post(route('workspaces.members.invite'), {
        email: email.value,
        name: name.value || null,
        role_name: role.value,
    }, {
        onSuccess: () => {
            email.value = '';
            name.value = '';
        },
    });
};
</script>

<template>
    <Head title="Members" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Workspace members"
                    description="Invite teammates to this workspace."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="members"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                    </template>
                </PageHeader>

                <div class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4">
                    <div class="min-w-[12rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Email</label>
                        <Input v-model="email" type="email" placeholder="user@example.com" />
                    </div>
                    <div class="min-w-[10rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Name</label>
                        <Input v-model="name" placeholder="Optional" />
                    </div>
                    <div class="w-32">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Role</label>
                        <select
                            v-model="role"
                            class="h-10 w-full rounded-md border border-input bg-white px-2 text-sm"
                        >
                            <option value="member">member</option>
                            <option value="admin">admin</option>
                            <option value="owner">owner</option>
                        </select>
                    </div>
                    <Button @click="invite">Invite</Button>
                </div>

                <DataTable
                    :is-empty="members.length === 0"
                    empty-title="No members"
                    empty-description="Invite someone to get started."
                >
                    <template #head>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                    </template>
                    <TableRow v-for="m in members" :key="m.id">
                        <TableCell class="font-medium">{{ m.user?.name ?? '—' }}</TableCell>
                        <TableCell>{{ m.user?.email ?? '—' }}</TableCell>
                        <TableCell class="capitalize">{{ m.role_name }}</TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
