<script setup lang="ts">
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
import { cn } from '@/lib/utils';

use([
    CanvasRenderer,
    BarChart,
    LineChart,
    PieChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
]);

const props = withDefaults(
    defineProps<{
        option: Record<string, unknown>;
        height?: string;
        class?: string;
    }>(),
    {
        height: '220px',
    },
);

const style = computed(() => ({ height: props.height, width: '100%' }));
</script>

<template>
    <div :class="cn('min-w-0', $props.class)" :style="style">
        <VChart
            class="h-full w-full"
            :option="option"
            :update-options="{ notMerge: true }"
            autoresize
        />
    </div>
</template>
