<script setup lang="ts">
import { computed } from 'vue';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-brand text-white hover:bg-brand-hover',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-slate-200',
                outline: 'border border-input bg-white hover:bg-accent hover:text-accent-foreground',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                destructive: 'bg-destructive text-destructive-foreground hover:bg-red-600',
                link: 'text-brand underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-10 px-4 py-2',
                sm: 'h-8 rounded-md px-3 text-xs',
                lg: 'h-11 rounded-md px-8',
                icon: 'h-10 w-10',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

type ButtonVariants = VariantProps<typeof buttonVariants>;

const props = withDefaults(
    defineProps<{
        variant?: ButtonVariants['variant'];
        size?: ButtonVariants['size'];
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        class?: string;
        as?: 'button' | 'a';
        href?: string;
    }>(),
    {
        variant: 'default',
        size: 'default',
        type: 'button',
        disabled: false,
        as: 'button',
    },
);

const classes = computed(() =>
    cn(buttonVariants({ variant: props.variant, size: props.size }), props.class),
);
</script>

<template>
    <a v-if="as === 'a'" :href="href" :class="classes">
        <slot />
    </a>
    <button v-else :type="type" :disabled="disabled" :class="classes">
        <slot />
    </button>
</template>
