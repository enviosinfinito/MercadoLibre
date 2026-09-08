<script setup lang="ts">
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        class?: string;
        stickyHead?: boolean;
    }>(),
    {
        stickyHead: false,
    },
);
</script>

<template>
    <div
        :class="
            cn(
                'w-full',
                stickyHead ? 'overflow-visible' : 'overflow-auto',
                $props.class,
            )
        "
    >
        <table class="w-full caption-bottom text-sm">
            <thead
                v-if="$slots.head"
                :class="
                    cn(
                        'border-b border-border bg-slate-50',
                        stickyHead &&
                            '[&_th]:sticky [&_th]:top-14 [&_th]:z-10 [&_th]:bg-slate-50 [&_th]:shadow-[0_1px_0_0_theme(colors.slate.200)]',
                    )
                "
            >
                <tr>
                    <slot name="head" />
                </tr>
            </thead>
            <tbody class="[&_tr:last-child]:border-0">
                <slot />
            </tbody>
        </table>
    </div>
</template>
