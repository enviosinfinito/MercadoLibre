<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface WorkspaceForm {
    id: number;
    name: string;
    slug: string;
    reporting_currency: string;
    default_costing_method: string;
}

const props = defineProps<{
    workspace: WorkspaceForm | null;
}>();

const isEdit = computed(() => props.workspace !== null);

const form = useForm({
    name: props.workspace?.name ?? '',
    slug: props.workspace?.slug ?? '',
    reporting_currency: props.workspace?.reporting_currency ?? 'MXN',
    default_costing_method: props.workspace?.default_costing_method ?? 'fifo',
});

const submit = () => {
    if (isEdit.value && props.workspace) {
        form.put(route('admin.workspaces.update', props.workspace.id));
    } else {
        form.post(route('admin.workspaces.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? 'Edit workspace' : 'New workspace'" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    :title="isEdit ? 'Edit workspace' : 'New workspace'"
                    description="Platform-wide workspace settings."
                >
                    <template #actions>
                        <Button
                            variant="outline"
                            as="a"
                            :href="
                                isEdit && workspace
                                    ? route('admin.workspaces.show', workspace.id)
                                    : route('admin.workspaces.index')
                            "
                        >
                            Cancel
                        </Button>
                    </template>
                </PageHeader>

                <form
                    class="space-y-4 rounded-lg border border-slate-200 bg-white p-6"
                    @submit.prevent="submit"
                >
                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Name</label>
                        <Input v-model="form.name" required />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Slug</label>
                        <Input v-model="form.slug" required />
                        <p v-if="form.errors.slug" class="mt-1 text-xs text-red-600">{{ form.errors.slug }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Currency</label>
                        <Input v-model="form.reporting_currency" maxlength="3" required />
                        <p v-if="form.errors.reporting_currency" class="mt-1 text-xs text-red-600">
                            {{ form.errors.reporting_currency }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">
                            Costing method
                        </label>
                        <select
                            v-model="form.default_costing_method"
                            class="h-10 w-full rounded-md border border-input bg-white px-2 text-sm"
                        >
                            <option value="fifo">fifo</option>
                            <option value="weighted_average">weighted_average</option>
                        </select>
                        <p v-if="form.errors.default_costing_method" class="mt-1 text-xs text-red-600">
                            {{ form.errors.default_costing_method }}
                        </p>
                    </div>
                    <Button type="submit" :disabled="form.processing">
                        {{ isEdit ? 'Save' : 'Create' }}
                    </Button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
