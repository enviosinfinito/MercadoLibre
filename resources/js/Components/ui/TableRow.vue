<script setup lang="ts">
import { cn, type ClassValue } from '@/lib/utils';

defineProps<{
    class?: ClassValue;
}>();
</script>

<template>
    <tr
        :class="
            cn(
                'border-b border-border transition-colors hover:bg-slate-50/80',
                $props.class,
            )
        "
    >
        <slot />
    </tr>
</template>
