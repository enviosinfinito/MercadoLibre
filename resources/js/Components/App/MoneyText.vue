<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        amount?: string | number | null;
        currency?: string | null;
        incomplete?: boolean;
        class?: string;
    }>(),
    {
        amount: null,
        currency: 'MXN',
        incomplete: false,
    },
);

const formatted = computed(() => {
    if (props.amount === null || props.amount === undefined || props.amount === '') {
        return '—';
    }

    const value = typeof props.amount === 'string' ? Number(props.amount) : props.amount;

    if (Number.isNaN(value)) {
        return String(props.amount);
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: props.currency || 'MXN',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    } catch {
        return `${value.toFixed(2)} ${props.currency ?? ''}`.trim();
    }
});
</script>

<template>
    <span
        :class="
            cn(
                'font-medium tabular-nums',
                incomplete ? 'text-amber-700' : 'text-slate-900',
                $props.class,
            )
        "
        :title="incomplete ? 'Incomplete calculation' : undefined"
    >
        {{ formatted }}
        <span v-if="incomplete" class="ml-1 text-xs font-normal text-amber-600">*</span>
    </span>
</template>
