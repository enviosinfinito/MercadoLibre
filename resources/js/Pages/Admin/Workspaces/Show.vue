<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import DataTable from '@/Components/App/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface MemberRow {
    id: number;
    role_name: string | null;
    user?: { id: number; name: string; email: string } | null;
}

interface WorkspaceShow {
    id: number;
    name: string;
    slug: string;
    reporting_currency: string;
    default_costing_method: string;
    memberships_count: number;
    connections_count: number;
    memberships: MemberRow[];
}

const props = defineProps<{
    workspace: WorkspaceShow;
}>();

const email = ref('');
const name = ref('');
const role = ref('member');

const invite = () => {
    if (!email.value.trim()) {
        return;
    }
    router.post(
        route('admin.workspaces.members.store', props.workspace.id),
        {
            email: email.value,
            name: name.value || null,
            role_name: role.value,
        },
        {
            onSuccess: () => {
                email.value = '';
                name.value = '';
            },
        },
    );
};

const updateRole = (membershipId: number, roleName: string) => {
    router.put(route('admin.workspaces.members.update', [props.workspace.id, membershipId]), {
        role_name: roleName,
    });
};

const removeMember = (membershipId: number) => {
    if (!confirm('Remove this member?')) {
        return;
    }
    router.delete(route('admin.workspaces.members.destroy', [props.workspace.id, membershipId]));
};

const switchInto = () => {
    router.post(route('admin.workspaces.switch', props.workspace.id));
};

const archive = () => {
    if (!confirm('Archive this workspace?')) {
        return;
    }
    router.delete(route('admin.workspaces.destroy', props.workspace.id));
};
</script>

<template>
    <Head :title="`Admin · ${workspace.name}`" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    :title="workspace.name"
                    :description="`${workspace.slug} · ${workspace.reporting_currency} · ${workspace.default_costing_method}`"
                >
                    <template #actions>
                        <Button size="sm" variant="outline" @click="switchInto">
                            Switch into
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            as="a"
                            :href="route('admin.workspaces.edit', workspace.id)"
                        >
                            Edit
                        </Button>
                        <Button size="sm" variant="destructive" @click="archive">
                            Archive
                        </Button>
                    </template>
                </PageHeader>

                <p class="mb-6 text-sm text-muted-foreground">
                    {{ workspace.memberships_count }} members ·
                    {{ workspace.connections_count }} connections
                </p>

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
                    :is-empty="workspace.memberships.length === 0"
                    empty-title="No members"
                    empty-description="Invite someone to this workspace."
                >
                    <template #head>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead />
                    </template>
                    <TableRow v-for="m in workspace.memberships" :key="m.id">
                        <TableCell class="font-medium">{{ m.user?.name ?? '—' }}</TableCell>
                        <TableCell>{{ m.user?.email ?? '—' }}</TableCell>
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
                            <Button size="sm" variant="ghost" @click="removeMember(m.id)">
                                Remove
                            </Button>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
