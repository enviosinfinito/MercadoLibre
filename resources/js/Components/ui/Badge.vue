<script setup lang="ts">
import { computed } from 'vue';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold transition-colors',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-brand text-white',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                outline: 'border-border text-foreground',
                success: 'border-transparent bg-emerald-100 text-emerald-800',
                warning: 'border-transparent bg-amber-100 text-amber-800',
                danger: 'border-transparent bg-red-100 text-red-800',
                muted: 'border-transparent bg-slate-100 text-slate-600',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

type BadgeVariants = VariantProps<typeof badgeVariants>;

const props = withDefaults(
    defineProps<{
        variant?: BadgeVariants['variant'];
        class?: string;
    }>(),
    {
        variant: 'default',
    },
);

const classes = computed(() => cn(badgeVariants({ variant: props.variant }), props.class));
</script>

<template>
    <div :class="classes">
        <slot />
    </div>
</template>
