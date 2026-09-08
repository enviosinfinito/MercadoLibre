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
import WidgetRenderer from '@/Components/Analytics/WidgetRenderer.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

interface WidgetDraft {
    id?: number;
    type: string;
    title: string;
    query: AnalyticsQuery;
    viz_options: Record<string, string | number | boolean> | null;
    grid: { x: number; y: number; w: number; h: number };
    sort_order: number;
}

interface Dashboard {
    id: number;
    name: string;
    description: string | null;
    visibility: string;
    widgets: WidgetDraft[];
    global_filters: Record<string, string | number | null> | null;
}

const props = defineProps<{
    dashboard: Dashboard;
    catalog: CatalogDataset[];
}>();

const name = ref(props.dashboard.name);
const description = ref(props.dashboard.description ?? '');
const visibility = ref(props.dashboard.visibility);
const widgets = ref<WidgetDraft[]>(
    (props.dashboard.widgets || []).map((w, i) => ({
        id: w.id,
        type: w.type,
        title: w.title,
        query: w.query,
        viz_options: w.viz_options,
        grid: w.grid || { x: (i % 2) * 6, y: Math.floor(i / 2) * 4, w: 6, h: 4 },
        sort_order: w.sort_order ?? i,
    })),
);

const selectedIndex = ref(0);
const processing = ref(false);
const preview = ref<{ columns: string[]; rows: Record<string, unknown>[]; meta?: { error?: string } } | null>(
    null,
);

const selected = () => widgets.value[selectedIndex.value];

const addWidget = () => {
    widgets.value.push({
        type: 'kpi',
        title: 'Nuevo widget',
        query: {
            dataset: 'profit',
            dimensions: [],
            measures: [{ field: 'profit', agg: 'sum', alias: 'profit_sum' }],
            filters: [],
            sort: [],
            limit: 100,
        },
        viz_options: { valueKey: 'profit_sum' },
        grid: { x: 0, y: widgets.value.length * 4, w: 6, h: 4 },
        sort_order: widgets.value.length,
    });
    selectedIndex.value = widgets.value.length - 1;
};

const removeWidget = (index: number) => {
    widgets.value.splice(index, 1);
    selectedIndex.value = Math.max(0, index - 1);
};

const runPreview = async () => {
    const widget = selected();
    if (!widget) return;
    try {
        const { data } = await axios.post(route('analytics.query'), { query: widget.query });
        preview.value = data;
    } catch (e: unknown) {
        const msg =
            typeof e === 'object' && e && 'response' in e
                ? // @ts-expect-error axios shape
                  e.response?.data?.message
                : 'Error';
        preview.value = { columns: [], rows: [], meta: { error: String(msg || 'Error') } };
    }
};

const save = () => {
    processing.value = true;
    router.put(
        route('analytics.dashboards.update', props.dashboard.id),
        {
            name: name.value,
            description: description.value,
            visibility: visibility.value,
            widgets: widgets.value,
        } as never,
        {
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Head :title="`Edit ${dashboard.name}`" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader title="Editar dashboard" :description="dashboard.name">
                    <template #actions>
                        <Button size="sm" variant="outline" as="a" :href="route('analytics.dashboards.show', dashboard.id)">
                            Ver
                        </Button>
                        <Button size="sm" :disabled="processing" @click="save">Guardar</Button>
                    </template>
                </PageHeader>

                <div class="mb-6 grid gap-3 sm:grid-cols-3">
                    <Input v-model="name" placeholder="Nombre" />
                    <Input v-model="description" placeholder="Descripción" />
                    <select
                        v-if="visibility !== 'platform_template'"
                        v-model="visibility"
                        class="h-10 rounded-md border border-slate-200 px-3 text-sm"
                    >
                        <option value="personal">Personal</option>
                        <option value="workspace">Workspace</option>
                    </select>
                </div>

                <div class="grid gap-6 lg:grid-cols-12">
                    <Card class="p-4 lg:col-span-3">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold">Widgets</h3>
                            <Button size="sm" variant="outline" @click="addWidget">+</Button>
                        </div>
                        <ul class="space-y-1">
                            <li v-for="(w, i) in widgets" :key="i">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-sm"
                                    :class="i === selectedIndex ? 'bg-slate-900 text-white' : 'hover:bg-slate-100'"
                                    @click="selectedIndex = i"
                                >
                                    <span class="truncate">{{ w.title }}</span>
                                    <span class="text-xs opacity-70">{{ w.type }}</span>
                                </button>
                            </li>
                        </ul>
                    </Card>

                    <Card v-if="selected()" class="space-y-4 p-4 lg:col-span-5">
                        <div class="flex flex-wrap gap-2">
                            <Input v-model="selected()!.title" class="flex-1" />
                            <select v-model="selected()!.type" class="h-10 rounded-md border px-2 text-sm">
                                <option value="kpi">KPI</option>
                                <option value="line">Line</option>
                                <option value="bar">Bar</option>
                                <option value="pie">Pie</option>
                                <option value="table">Table</option>
                                <option value="pivot">Pivot</option>
                            </select>
                            <Button size="sm" variant="outline" @click="removeWidget(selectedIndex)">Eliminar</Button>
                        </div>
                        <QueryBuilder
                            v-model="selected()!.query"
                            :catalog="catalog"
                            @preview="runPreview"
                        />
                    </Card>

                    <Card class="min-h-[320px] p-4 lg:col-span-4">
                        <h3 class="mb-3 text-sm font-semibold">Preview</h3>
                        <WidgetRenderer
                            v-if="selected()"
                            :type="selected()!.type"
                            :title="selected()!.title"
                            :result="preview"
                            :viz-options="selected()!.viz_options"
                        />
                    </Card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
