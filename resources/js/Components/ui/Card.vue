<script setup lang="ts">
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        class?: string;
        contentClass?: string;
    }>(),
    {
        contentClass: 'p-6',
    },
);
</script>

<template>
    <div
        :class="
            cn(
                'rounded-lg border border-border bg-card text-card-foreground shadow-sm',
                $props.class,
            )
        "
    >
        <div
            v-if="$slots.header"
            class="flex flex-col gap-1.5 border-b border-border px-6 py-4"
        >
            <slot name="header" />
        </div>
        <div :class="cn(contentClass)">
            <slot />
        </div>
        <div v-if="$slots.footer" class="border-t border-border px-6 py-4">
            <slot name="footer" />
        </div>
    </div>
</template>
