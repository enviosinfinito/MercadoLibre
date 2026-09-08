<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link } from '@inertiajs/vue3';

interface TemplateRow {
    id: number;
    name: string;
    description: string | null;
    slug: string | null;
    is_home: boolean;
    widgets_count?: number;
}

defineProps<{
    templates: TemplateRow[];
}>();
</script>

<template>
    <Head title="Analytics templates" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Analytics templates"
                    description="Plantillas globales consumidas por cada workspace con sus datos."
                >
                    <template #actions>
                        <Button as="a" size="sm" :href="route('admin.analytics.templates.create')">
                            Nueva plantilla
                        </Button>
                    </template>
                </PageHeader>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Card v-for="t in templates" :key="t.id" class="p-4">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold">{{ t.name }}</h3>
                            <span v-if="t.is_home" class="text-xs text-brand">Home</span>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">{{ t.description || '—' }}</p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ t.widgets_count ?? 0 }} widgets · {{ t.slug || 'sin slug' }}
                        </p>
                        <div class="mt-4">
                            <Button as="a" size="sm" :href="route('admin.analytics.templates.edit', t.id)">
                                Editar
                            </Button>
                            <Link
                                :href="route('admin.analytics.templates.destroy', t.id)"
                                method="delete"
                                as="button"
                                class="ml-2 inline-flex h-8 items-center rounded-md border px-3 text-xs text-red-600"
                            >
                                Eliminar
                            </Link>
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
