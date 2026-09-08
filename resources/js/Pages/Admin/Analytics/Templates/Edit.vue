<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Input from '@/Components/ui/Input.vue';
import QueryBuilder, {
    type AnalyticsQuery,
    type CatalogDataset,
} from '@/Components/Analytics/QueryBuilder.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface WidgetDraft {
    id?: number;
    type: string;
    title: string;
    query: AnalyticsQuery;
    viz_options?: Record<string, string | number | boolean> | null;
    grid?: { x: number; y: number; w: number; h: number };
    sort_order?: number;
}

const props = defineProps<{
    dashboard: {
        id: number;
        name: string;
        description: string | null;
        slug: string | null;
        is_home: boolean;
        widgets: WidgetDraft[];
    } | null;
    catalog: CatalogDataset[];
}>();

const name = ref(props.dashboard?.name ?? '');
const description = ref(props.dashboard?.description ?? '');
const slug = ref(props.dashboard?.slug ?? '');
const isHome = ref(props.dashboard?.is_home ?? false);
const widgets = ref<WidgetDraft[]>([...(props.dashboard?.widgets ?? [])]);
const selectedIndex = ref(0);
const processing = ref(false);

const addWidget = () => {
    widgets.value.push({
        type: 'kpi',
        title: 'KPI',
        query: {
            dataset: 'profit',
            dimensions: [],
            measures: [{ field: 'profit', agg: 'sum', alias: 'profit_sum' }],
            filters: [],
            sort: [],
            limit: 1,
        },
        grid: { x: 0, y: widgets.value.length * 2, w: 3, h: 2 },
    });
    selectedIndex.value = widgets.value.length - 1;
};

const save = () => {
    processing.value = true;
    const payload = {
        name: name.value,
        description: description.value,
        slug: slug.value,
        is_home: isHome.value,
        widgets: widgets.value,
    };
    const opts = {
        onFinish: () => {
            processing.value = false;
        },
    };
    if (props.dashboard) {
        router.put(route('admin.analytics.templates.update', props.dashboard.id), payload as never, opts);
    } else {
        router.post(route('admin.analytics.templates.store'), payload as never, opts);
    }
};
</script>

<template>
    <Head :title="dashboard ? 'Edit template' : 'New template'" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader :title="dashboard ? 'Editar plantilla' : 'Nueva plantilla'">
                    <template #actions>
                        <Button size="sm" :disabled="processing" @click="save">Guardar</Button>
                    </template>
                </PageHeader>

                <div class="mb-4 grid gap-3 sm:grid-cols-4">
                    <Input v-model="name" placeholder="Nombre" class="sm:col-span-2" />
                    <Input v-model="slug" placeholder="slug" />
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="isHome" type="checkbox" />
                        Home template
                    </label>
                </div>
                <Input v-model="description" class="mb-6" placeholder="Descripción" />

                <div class="grid gap-4 lg:grid-cols-12">
                    <Card class="p-4 lg:col-span-3">
                        <div class="mb-2 flex justify-between">
                            <h3 class="text-sm font-semibold">Widgets</h3>
                            <Button size="sm" variant="outline" @click="addWidget">+</Button>
                        </div>
                        <button
                            v-for="(w, i) in widgets"
                            :key="i"
                            type="button"
                            class="mb-1 block w-full rounded px-2 py-1 text-left text-sm"
                            :class="i === selectedIndex ? 'bg-slate-900 text-white' : 'hover:bg-slate-100'"
                            @click="selectedIndex = i"
                        >
                            {{ w.title }}
                        </button>
                    </Card>
                    <Card v-if="widgets[selectedIndex]" class="space-y-3 p-4 lg:col-span-9">
                        <div class="flex gap-2">
                            <Input v-model="widgets[selectedIndex].title" class="flex-1" />
                            <select
                                v-model="widgets[selectedIndex].type"
                                class="h-10 rounded-md border px-2 text-sm"
                            >
                                <option value="kpi">KPI</option>
                                <option value="line">Line</option>
                                <option value="bar">Bar</option>
                                <option value="pie">Pie</option>
                                <option value="table">Table</option>
                                <option value="pivot">Pivot</option>
                            </select>
                        </div>
                        <QueryBuilder v-model="widgets[selectedIndex].query" :catalog="catalog" />
                    </Card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
