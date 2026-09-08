<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ConnectionHealth from '@/Components/Domain/ConnectionHealth.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

interface ConnectionShow {
    id: number;
    workspace_id: number;
    provider: string;
    external_user_id: string | null;
    site_id: string | null;
    status: string;
    needs_reauthorization: boolean;
    freshness_status: string | null;
    last_synced_at: string | null;
    last_error_redacted: string | null;
    workspace?: { id: number; name: string; slug: string } | null;
}

const props = defineProps<{
    connection: ConnectionShow;
}>();

const form = useForm({
    status: props.connection.status,
    needs_reauthorization: props.connection.needs_reauthorization,
});

const save = () => {
    form.put(route('admin.connections.update', props.connection.id));
};

const disconnect = () => {
    if (!confirm('Disconnect this connection?')) {
        return;
    }
    router.delete(route('admin.connections.destroy', props.connection.id));
};
</script>

<template>
    <Head :title="`Admin · Connection #${connection.id}`" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    :title="`${connection.provider} · ${connection.external_user_id ?? connection.id}`"
                    :description="
                        connection.workspace
                            ? `${connection.workspace.name} (${connection.workspace.slug})`
                            : 'No workspace'
                    "
                >
                    <template #actions>
                        <Button
                            size="sm"
                            variant="outline"
                            as="a"
                            :href="route('admin.connections.index')"
                        >
                            Back
                        </Button>
                        <Button size="sm" variant="destructive" @click="disconnect">
                            Disconnect
                        </Button>
                    </template>
                </PageHeader>

                <div class="mb-6 space-y-3 rounded-lg border border-slate-200 bg-white p-6 text-sm">
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">Health</span>
                        <ConnectionHealth
                            :status="connection.status"
                            :freshness-status="connection.freshness_status"
                            :needs-reauthorization="connection.needs_reauthorization"
                            :last-synced-at="connection.last_synced_at"
                        />
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">Site</span>
                        <span>{{ connection.site_id ?? '—' }}</span>
                    </div>
                    <div v-if="connection.last_error_redacted" class="flex justify-between gap-4">
                        <span class="text-muted-foreground">Last error</span>
                        <span class="max-w-sm text-right text-red-700">
                            {{ connection.last_error_redacted }}
                        </span>
                    </div>
                </div>

                <form
                    class="space-y-4 rounded-lg border border-slate-200 bg-white p-6"
                    @submit.prevent="save"
                >
                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">
                            Status
                        </label>
                        <select
                            v-model="form.status"
                            class="h-10 w-full rounded-md border border-input bg-white px-2 text-sm"
                        >
                            <option value="pending">pending</option>
                            <option value="active">active</option>
                            <option value="error">error</option>
                            <option value="disabled">disabled</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="form.needs_reauthorization"
                            type="checkbox"
                            class="rounded border-slate-300"
                        />
                        Needs reauthorization
                    </label>
                    <Button type="submit" :disabled="form.processing">Save</Button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
