<script setup lang="ts">
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
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
                'flex flex-col sm:flex-row sm:items-end sm:justify-between',
                compact ? 'mb-3 gap-2' : 'mb-6 gap-4',
            )
        "
    >
        <div class="min-w-0">
            <h1
                :class="
                    cn(
                        'font-semibold tracking-tight text-slate-900',
                        compact ? 'text-lg' : 'text-2xl',
                    )
                "
            >
                {{ title }}
            </h1>
            <p
                v-if="description"
                :class="
                    cn(
                        'text-muted-foreground',
                        compact ? 'mt-0.5 truncate text-xs' : 'mt-1 text-sm',
                    )
                "
            >
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
