<script setup lang="ts">
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        class?: string;
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col sm:flex-row sm:items-center sm:justify-between',
                compact
                    ? 'mb-3 gap-2 rounded-xl border border-slate-200/70 bg-white/90 px-2.5 py-2 shadow-[0_1px_2px_rgba(15,23,42,0.04)]'
                    : 'mb-4 gap-3 rounded-lg border border-border bg-white p-3',
                $props.class,
            )
        "
    >
        <div
            :class="
                cn(
                    'flex flex-1 flex-wrap items-center',
                    compact ? 'gap-1.5' : 'gap-2',
                )
            "
        >
            <slot />
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
