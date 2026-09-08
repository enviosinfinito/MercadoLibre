<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface UserForm {
    id: number;
    name: string;
    email: string;
    is_platform_admin: boolean;
}

const props = defineProps<{
    user: UserForm | null;
}>();

const isEdit = computed(() => props.user !== null);

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    password_confirmation: '',
    is_platform_admin: props.user?.is_platform_admin ?? false,
});

const submit = () => {
    if (isEdit.value && props.user) {
        form.put(route('admin.users.update', props.user.id));
    } else {
        form.post(route('admin.users.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? 'Edit user' : 'New user'" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    :title="isEdit ? 'Edit user' : 'New user'"
                    description="Platform user account."
                >
                    <template #actions>
                        <Button
                            variant="outline"
                            as="a"
                            :href="
                                isEdit && user
                                    ? route('admin.users.show', user.id)
                                    : route('admin.users.index')
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
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Email</label>
                        <Input v-model="form.email" type="email" required />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">
                            Password
                            <span v-if="isEdit" class="font-normal">(leave blank to keep)</span>
                        </label>
                        <Input v-model="form.password" type="password" :required="!isEdit" />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">
                            {{ form.errors.password }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">
                            Confirm password
                        </label>
                        <Input v-model="form.password_confirmation" type="password" :required="!isEdit" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="form.is_platform_admin"
                            type="checkbox"
                            class="rounded border-slate-300"
                        />
                        Platform admin
                    </label>
                    <Button type="submit" :disabled="form.processing">
                        {{ isEdit ? 'Save' : 'Create' }}
                    </Button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
