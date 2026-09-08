<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import DataTable from '@/Components/App/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface MembershipRow {
    id: number;
    role_name: string | null;
    workspace?: { id: number; name: string; slug: string } | null;
}

interface UserShow {
    id: number;
    name: string;
    email: string;
    is_platform_admin: boolean;
    memberships: MembershipRow[];
}

interface WorkspaceOption {
    id: number;
    name: string;
    slug: string;
}

const props = defineProps<{
    user: UserShow;
    workspaces: WorkspaceOption[];
}>();

const workspaceId = ref<number | ''>('');
const role = ref('member');

const addMembership = () => {
    if (!workspaceId.value) {
        return;
    }
    router.post(
        route('admin.users.memberships.store', props.user.id),
        {
            workspace_id: workspaceId.value,
            role_name: role.value,
        },
        {
            onSuccess: () => {
                workspaceId.value = '';
            },
        },
    );
};

const updateRole = (membershipId: number, roleName: string) => {
    router.put(route('admin.users.memberships.update', [props.user.id, membershipId]), {
        role_name: roleName,
    });
};

const removeMembership = (membershipId: number) => {
    if (!confirm('Remove this membership?')) {
        return;
    }
    router.delete(route('admin.users.memberships.destroy', [props.user.id, membershipId]));
};

const destroyUser = () => {
    if (!confirm('Delete this user permanently?')) {
        return;
    }
    router.delete(route('admin.users.destroy', props.user.id));
};
</script>

<template>
    <Head :title="`Admin · ${user.name}`" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <PageHeader :title="user.name" :description="user.email">
                    <template #actions>
                        <Badge v-if="user.is_platform_admin" variant="warning">
                            Platform admin
                        </Badge>
                        <Button
                            size="sm"
                            variant="outline"
                            as="a"
                            :href="route('admin.users.edit', user.id)"
                        >
                            Edit
                        </Button>
                        <Button size="sm" variant="destructive" @click="destroyUser">
                            Delete
                        </Button>
                    </template>
                </PageHeader>

                <div class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4">
                    <div class="min-w-[14rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">
                            Workspace
                        </label>
                        <select
                            v-model="workspaceId"
                            class="h-10 w-full rounded-md border border-input bg-white px-2 text-sm"
                        >
                            <option value="">Select…</option>
                            <option
                                v-for="ws in workspaces"
                                :key="ws.id"
                                :value="ws.id"
                            >
                                {{ ws.name }} ({{ ws.slug }})
                            </option>
                        </select>
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
                    <Button @click="addMembership">Add membership</Button>
                </div>

                <DataTable
                    :is-empty="user.memberships.length === 0"
                    empty-title="No memberships"
                    empty-description="Add this user to a workspace."
                >
                    <template #head>
                        <TableHead>Workspace</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead />
                    </template>
                    <TableRow v-for="m in user.memberships" :key="m.id">
                        <TableCell class="font-medium">
                            {{ m.workspace?.name ?? '—' }}
                            <span class="ml-1 font-mono text-xs text-muted-foreground">
                                {{ m.workspace?.slug }}
                            </span>
                        </TableCell>
                        <TableCell>
                            <select
                                class="h-8 rounded-md border border-input bg-white px-2 text-sm"
                                :value="m.role_name ?? 'member'"
                                @change="
                                    updateRole(
                                        m.id,
                                        ($event.target as HTMLSelectElement).value,
                                    )
                                "
                            >
                                <option value="member">member</option>
                                <option value="admin">admin</option>
                                <option value="owner">owner</option>
                            </select>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button size="sm" variant="ghost" @click="removeMembership(m.id)">
                                Remove
                            </Button>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
