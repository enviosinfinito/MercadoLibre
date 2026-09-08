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

interface ReportRow {
    id: number;
    name: string;
    viz_type: string;
    visibility: string;
}

const props = defineProps<{
    catalog: CatalogDataset[];
    query: AnalyticsQuery;
    preview: { columns?: string[]; rows?: Record<string, unknown>[]; error?: string } | null;
    reports: ReportRow[];
}>();

const queryModel = ref<AnalyticsQuery>(structuredClone(props.query));
const vizType = ref('table');
const livePreview = ref(props.preview);
const reportName = ref('');
const visibility = ref<'personal' | 'workspace'>('personal');
const processing = ref(false);

const runPreview = async () => {
    try {
        const { data } = await axios.post(route('analytics.query'), { query: queryModel.value });
        livePreview.value = data;
    } catch (e: unknown) {
        const msg =
            typeof e === 'object' && e && 'response' in e
                ? // @ts-expect-error axios
                  e.response?.data?.message
                : 'Error';
        livePreview.value = { error: String(msg), columns: [], rows: [] };
    }
};

const saveReport = () => {
    processing.value = true;
    router.post(
        route('analytics.reports.store'),
        {
            name: reportName.value,
            description: '',
            visibility: visibility.value,
            viz_type: vizType.value,
            query: queryModel.value,
        } as never,
        {
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};

const exportQuery = async () => {
    const { startExport } = await import('@/composables/useExportStart');
    await startExport({
        targetModule: 'analytics_query',
        selectionMode: 'filter',
        filters: { name: reportName.value || 'explore_export' },
        query: queryModel.value,
        label: 'Analytics export',
    });
};
</script>

<template>
    <Head title="Analytics Explorer" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Explorer"
                    description="Construye reportes con dimensiones, métricas, filtros, pivot y fórmulas."
                >
                    <template #actions>
                        <Button size="sm" variant="outline" as="a" :href="route('analytics.dashboards.index')">
                            Dashboards
                        </Button>
                        <Button size="sm" variant="outline" @click="exportQuery">Exportar Excel</Button>
                    </template>
                </PageHeader>

                <div class="grid gap-6 lg:grid-cols-12">
                    <Card class="p-4 lg:col-span-5">
                        <QueryBuilder v-model="queryModel" :catalog="catalog" @preview="runPreview" />
                        <div class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                            <Input v-model="reportName" placeholder="Nombre del reporte" />
                            <div class="flex gap-2">
                                <select v-model="vizType" class="h-10 rounded-md border px-2 text-sm">
                                    <option value="table">Table</option>
                                    <option value="line">Line</option>
                                    <option value="bar">Bar</option>
                                    <option value="pie">Pie</option>
                                    <option value="pivot">Pivot</option>
                                    <option value="kpi">KPI</option>
                                </select>
                                <select v-model="visibility" class="h-10 rounded-md border px-2 text-sm">
                                    <option value="personal">Personal</option>
                                    <option value="workspace">Workspace</option>
                                </select>
                                <Button size="sm" :disabled="processing" @click="saveReport">
                                    Guardar
                                </Button>
                            </div>
                        </div>
                    </Card>

                    <div class="space-y-4 lg:col-span-7">
                        <Card class="min-h-[360px] p-4">
                            <p v-if="livePreview?.error" class="text-sm text-red-600">{{ livePreview.error }}</p>
                            <WidgetRenderer
                                v-else
                                :type="vizType"
                                title="Preview"
                                :result="livePreview as any"
                            />
                        </Card>

                        <Card class="p-4">
                            <h3 class="mb-2 text-sm font-semibold">Reportes guardados</h3>
                            <ul class="divide-y divide-slate-100">
                                <li
                                    v-for="r in reports"
                                    :key="r.id"
                                    class="flex items-center justify-between py-2 text-sm"
                                >
                                    <div>
                                        <p class="font-medium">{{ r.name }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ r.viz_type }} · {{ r.visibility }}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="router.post(route('analytics.reports.export', r.id))"
                                    >
                                        Export
                                    </Button>
                                </li>
                                <li v-if="!reports.length" class="py-4 text-sm text-muted-foreground">
                                    Sin reportes aún.
                                </li>
                            </ul>
                        </Card>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
