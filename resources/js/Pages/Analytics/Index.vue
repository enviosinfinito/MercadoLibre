<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface DashboardRow {
    id: number;
    name: string;
    description: string | null;
    visibility: string;
    widgets_count?: number;
    is_home?: boolean;
}

defineProps<{
    templates: DashboardRow[];
    workspaceDashboards: DashboardRow[];
    personalDashboards: DashboardRow[];
}>();

const form = useForm({
    name: '',
    description: '',
    visibility: 'personal' as 'personal' | 'workspace',
});

const create = () => {
    form.post(route('analytics.dashboards.store'));
};
</script>

<template>
    <Head title="Analytics" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Analytics"
                    description="Plantillas de plataforma, dashboards del workspace y explorador de reportes."
                >
                    <template #actions>
                        <Button as="a" size="sm" variant="outline" :href="route('analytics.explore')">
                            Explorer
                        </Button>
                        <Button as="a" size="sm" variant="outline" :href="route('analytics.schedules.index')">
                            Schedules
                        </Button>
                    </template>
                </PageHeader>

                <Card class="mb-8 p-4">
                    <h3 class="mb-3 text-sm font-semibold">Nuevo dashboard</h3>
                    <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="create">
                        <div class="flex-1">
                            <Input v-model="form.name" placeholder="Nombre" required />
                        </div>
                        <select
                            v-model="form.visibility"
                            class="h-10 rounded-md border border-slate-200 px-3 text-sm"
                        >
                            <option value="personal">Personal</option>
                            <option value="workspace">Workspace</option>
                        </select>
                        <Button type="submit" size="sm" :disabled="form.processing">Crear</Button>
                    </form>
                </Card>

                <section class="mb-8">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Plantillas de plataforma
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Card v-for="d in templates" :key="d.id" class="p-4">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="font-semibold text-slate-900">{{ d.name }}</h3>
                                <span v-if="d.is_home" class="text-xs text-brand">Home</span>
                            </div>
                            <p class="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                {{ d.description || 'Sin descripción' }}
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground">{{ d.widgets_count ?? 0 }} widgets</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Button as="a" size="sm" :href="route('analytics.dashboards.show', d.id)">
                                    Abrir
                                </Button>
                                <Link
                                    :href="route('analytics.dashboards.clone', d.id)"
                                    method="post"
                                    as="button"
                                    class="inline-flex h-8 items-center rounded-md border border-input bg-white px-3 text-xs"
                                >
                                    Clonar
                                </Link>
                            </div>
                        </Card>
                    </div>
                </section>

                <section class="mb-8">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Workspace
                    </h2>
                    <div v-if="workspaceDashboards.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Card v-for="d in workspaceDashboards" :key="d.id" class="p-4">
                            <h3 class="font-semibold text-slate-900">{{ d.name }}</h3>
                            <p class="mt-1 text-sm text-muted-foreground">{{ d.description || '—' }}</p>
                            <div class="mt-4 flex gap-2">
                                <Button as="a" size="sm" :href="route('analytics.dashboards.show', d.id)">
                                    Abrir
                                </Button>
                                <Button as="a" size="sm" variant="outline" :href="route('analytics.dashboards.edit', d.id)">
                                    Editar
                                </Button>
                            </div>
                        </Card>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">No hay dashboards compartidos del workspace.</p>
                </section>

                <section>
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Personales
                    </h2>
                    <div v-if="personalDashboards.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Card v-for="d in personalDashboards" :key="d.id" class="p-4">
                            <h3 class="font-semibold text-slate-900">{{ d.name }}</h3>
                            <p class="mt-1 text-sm text-muted-foreground">{{ d.description || '—' }}</p>
                            <div class="mt-4 flex gap-2">
                                <Button as="a" size="sm" :href="route('analytics.dashboards.show', d.id)">
                                    Abrir
                                </Button>
                                <Button as="a" size="sm" variant="outline" :href="route('analytics.dashboards.edit', d.id)">
                                    Editar
                                </Button>
                            </div>
                        </Card>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">Aún no tienes dashboards personales.</p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
