<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import FilterPanel from '@/Components/Analytics/FilterPanel.vue';
import WidgetRenderer, { type WidgetResult } from '@/Components/Analytics/WidgetRenderer.vue';
import Dialog from '@/Components/ui/Dialog.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

interface Widget {
    id: number;
    type: string;
    title: string;
    query: Record<string, unknown>;
    viz_options: Record<string, unknown> | null;
    grid: { x: number; y: number; w: number; h: number } | null;
}

interface Dashboard {
    id: number;
    name: string;
    description: string | null;
    visibility: string;
    widgets: Widget[];
}

const props = defineProps<{
    dashboard: Dashboard;
    widgetResults: Record<number, WidgetResult>;
    connections: Array<{
        id: number;
        provider: string;
        external_user_id: string | null;
        site_id: string | null;
    }>;
    filters: Record<string, string | null | undefined>;
    canEdit: boolean;
}>();

const sortedWidgets = computed(() =>
    [...props.dashboard.widgets].sort((a, b) => {
        const ay = a.grid?.y ?? 0;
        const by = b.grid?.y ?? 0;
        if (ay !== by) return ay - by;
        return (a.grid?.x ?? 0) - (b.grid?.x ?? 0);
    }),
);

const drillOpen = ref(false);
const drillRows = ref<Record<string, unknown>[]>([]);
const drillColumns = ref<string[]>([]);

const onDrill = async (widget: Widget, payload: { field: string; value: unknown }) => {
    try {
        const { data } = await axios.post(route('analytics.drillthrough'), {
            query: widget.query,
            filters: [{ field: payload.field, op: 'eq', value: payload.value }],
        });
        drillColumns.value = data.columns ?? [];
        drillRows.value = data.rows ?? [];
        drillOpen.value = true;
    } catch {
        // ignore
    }
};

const exportDashboard = () => {
    const first = props.dashboard.widgets[0];
    if (!first) return;
    router.post(route('analytics.export-query'), {
        query: first.query,
        format: 'csv',
        name: props.dashboard.name,
    } as never);
};
</script>

<template>
    <Head :title="dashboard.name" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader :title="dashboard.name" :description="dashboard.description || undefined">
                    <template #actions>
                        <Button as="a" size="sm" variant="outline" :href="route('analytics.dashboards.index')">
                            Biblioteca
                        </Button>
                        <Button
                            v-if="canEdit"
                            as="a"
                            size="sm"
                            variant="outline"
                            :href="route('analytics.dashboards.edit', dashboard.id)"
                        >
                            Editar
                        </Button>
                        <Link
                            :href="route('analytics.dashboards.clone', dashboard.id)"
                            method="post"
                            as="button"
                            class="inline-flex h-8 items-center rounded-md border border-input bg-white px-3 text-xs"
                        >
                            Clonar
                        </Link>
                        <Button size="sm" variant="outline" @click="exportDashboard">Export</Button>
                    </template>
                </PageHeader>

                <FilterPanel
                    :filters="filters"
                    :connections="connections"
                    :dashboard-id="dashboard.id"
                />

                <div class="grid grid-cols-1 gap-4 md:grid-cols-12">
                    <div
                        v-for="widget in sortedWidgets"
                        :key="widget.id"
                        class="min-h-[200px]"
                        :class="[
                            widget.grid?.w && widget.grid.w >= 9
                                ? 'md:col-span-12'
                                : widget.grid?.w && widget.grid.w >= 6
                                  ? 'md:col-span-6'
                                  : 'md:col-span-3',
                        ]"
                    >
                        <WidgetRenderer
                            :type="widget.type"
                            :title="widget.title"
                            :result="widgetResults[widget.id]"
                            :viz-options="widget.viz_options"
                            @drill="(p) => onDrill(widget, p)"
                        />
                    </div>
                </div>
            </div>
        </div>

        <Dialog
            :open="drillOpen"
            title="Drill-through"
            class="max-w-4xl"
            @close="drillOpen = false"
        >
            <div class="max-h-[60vh] overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th v-for="c in drillColumns" :key="c" class="border-b px-2 py-1">{{ c }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in drillRows" :key="i">
                            <td v-for="c in drillColumns" :key="c" class="border-b px-2 py-1">
                                {{ row[c] ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Dialog>
    </AuthenticatedLayout>
</template>
