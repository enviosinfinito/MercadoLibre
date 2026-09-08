<script setup lang="ts">
import Card from '@/Components/ui/Card.vue';
import { computed } from 'vue';
import VChart from 'vue-echarts';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import {
    GridComponent,
    TooltipComponent,
    LegendComponent,
} from 'echarts/components';

use([CanvasRenderer, BarChart, LineChart, PieChart, GridComponent, TooltipComponent, LegendComponent]);

export interface WidgetResult {
    columns: string[];
    rows: Record<string, unknown>[];
    meta?: { error?: string; row_count?: number };
}

const props = defineProps<{
    type: string;
    title: string;
    result?: WidgetResult | null;
    vizOptions?: Record<string, unknown> | null;
}>();

const emit = defineEmits<{
    drill: [payload: { field: string; value: unknown }];
}>();

const error = computed(() => props.result?.meta?.error);
const rows = computed(() => props.result?.rows ?? []);
const columns = computed(() => props.result?.columns ?? []);

const valueKey = computed(() => {
    const fromOpts = props.vizOptions?.valueKey;
    if (typeof fromOpts === 'string' && fromOpts) return fromOpts;
    return columns.value.find((c) => c !== columns.value[0]) ?? columns.value[0] ?? 'value';
});

const categoryKey = computed(() => columns.value[0] ?? 'category');

const kpiValue = computed(() => {
    const row = rows.value[0];
    if (!row) return '—';
    const raw = row[valueKey.value];
    if (raw == null) return '—';
    const num = Number(raw);
    if (props.vizOptions?.format === 'percent') {
        return Number.isFinite(num) ? `${(num * 100).toFixed(1)}%` : '—';
    }
    return Number.isFinite(num) ? num.toLocaleString(undefined, { maximumFractionDigits: 2 }) : String(raw);
});

const chartOption = computed(() => {
    if (!['line', 'bar', 'pie'].includes(props.type)) return null;
    const cats = rows.value.map((r) => String(r[categoryKey.value] ?? ''));
    const vals = rows.value.map((r) => Number(r[valueKey.value] ?? 0));

    if (props.type === 'pie') {
        return {
            tooltip: { trigger: 'item' },
            series: [
                {
                    type: 'pie',
                    radius: ['35%', '70%'],
                    data: cats.map((name, i) => ({ name, value: vals[i] })),
                },
            ],
        };
    }

    return {
        tooltip: { trigger: 'axis' },
        grid: { left: 40, right: 16, top: 24, bottom: 32 },
        xAxis: { type: 'category', data: cats },
        yAxis: { type: 'value' },
        series: [
            {
                type: props.type,
                data: vals,
                smooth: props.type === 'line',
            },
        ],
    };
});

const onChartClick = (params: { name?: string }) => {
    if (params.name != null) {
        emit('drill', { field: categoryKey.value.replace(/_day$|_month$|_week$|_year$/, ''), value: params.name });
    }
};
</script>

<template>
    <Card class="flex h-full flex-col overflow-hidden p-4">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h3 class="truncate text-sm font-semibold text-slate-900">{{ title }}</h3>
            <span v-if="result?.meta?.row_count != null" class="text-xs text-muted-foreground">
                {{ result.meta.row_count }} rows
            </span>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-else-if="type === 'kpi'" class="flex flex-1 items-center">
            <p class="text-3xl font-semibold tracking-tight text-slate-900">{{ kpiValue }}</p>
        </div>

        <div v-else-if="chartOption" class="min-h-[180px] flex-1">
            <VChart class="h-full w-full" :option="chartOption" autoresize @click="onChartClick" />
        </div>

        <div v-else class="flex-1 overflow-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-muted-foreground">
                        <th v-for="col in columns" :key="col" class="px-2 py-1 font-medium">{{ col }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, idx) in rows"
                        :key="idx"
                        class="border-b border-slate-100 hover:bg-slate-50"
                    >
                        <td v-for="col in columns" :key="col" class="px-2 py-1.5 tabular-nums">
                            {{ row[col] ?? '—' }}
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td :colspan="columns.length || 1" class="px-2 py-6 text-center text-muted-foreground">
                            Sin datos
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
