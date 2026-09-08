<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import DataTable from '@/Components/App/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useWorkspaceStore } from '@/stores/workspace';

interface WorkspaceRow {
    id: number;
    name: string;
    slug: string;
    reporting_currency?: string;
    role?: string;
}

const props = defineProps<{
    workspaces: WorkspaceRow[];
}>();

const page = usePage();
const store = useWorkspaceStore();

const currentId = computed(() => {
    const shared = (page.props as { workspace?: { id?: number } }).workspace?.id;
    return shared ?? store.currentWorkspaceId;
});

const switchTo = (id: number) => {
    store.setWorkspaceId(id);
    router.post(route('workspaces.switch'), { workspace_id: id });
};
</script>

<template>
    <Head title="Workspaces" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Workspaces"
                    description="Switch the active workspace for this session."
                >
                    <template #actions>
                        <Button
                            size="sm"
                            variant="outline"
                            as="a"
                            :href="route('workspaces.members.index')"
                        >
                            Members
                        </Button>
                    </template>
                </PageHeader>

                <DataTable
                    :is-empty="workspaces.length === 0"
                    empty-title="No workspaces"
                    empty-description="You are not a member of any workspace yet."
                >
                    <template #head>
                        <TableHead>Name</TableHead>
                        <TableHead>Slug</TableHead>
                        <TableHead>Currency</TableHead>
                        <TableHead />
                    </template>
                    <TableRow v-for="ws in workspaces" :key="ws.id">
                        <TableCell class="font-medium">
                            {{ ws.name }}
                            <Badge
                                v-if="currentId === ws.id"
                                variant="success"
                                class="ml-2"
                            >
                                Current
                            </Badge>
                        </TableCell>
                        <TableCell class="font-mono text-xs">{{ ws.slug }}</TableCell>
                        <TableCell>{{ ws.reporting_currency ?? '—' }}</TableCell>
                        <TableCell class="text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="currentId === ws.id"
                                @click="switchTo(ws.id)"
                            >
                                Switch
                            </Button>
                        </TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
