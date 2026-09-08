<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    values?: number[];
}>();

const path = computed(() => {
    const values = props.values?.length ? props.values : [0];
    const max = Math.max(...values, 0.001);
    const min = Math.min(...values, 0);
    const w = 64;
    const h = 20;
    return values
        .map((v, i) => {
            const x = (i / Math.max(values.length - 1, 1)) * w;
            const y = h - ((v - min) / (max - min || 1)) * (h - 2) - 1;
            return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});
</script>

<template>
    <svg
        viewBox="0 0 64 20"
        class="h-5 w-16 text-slate-500"
        aria-hidden="true"
    >
        <path
            :d="path"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
