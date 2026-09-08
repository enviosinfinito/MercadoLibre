<script setup lang="ts">
import { cn, type ClassValue } from '@/lib/utils';
import { computed, inject, type Ref } from 'vue';

defineProps<{
    class?: ClassValue;
}>();

const compactRef = inject<boolean | Ref<boolean>>('dataTableCompact', false);
const compact = computed(() =>
    typeof compactRef === 'object' && compactRef != null && 'value' in compactRef
        ? Boolean(compactRef.value)
        : Boolean(compactRef),
);
</script>

<template>
    <th
        :class="
            cn(
                'text-left align-middle font-semibold uppercase tracking-wide text-muted-foreground',
                compact
                    ? 'h-8 px-3 text-[10px]'
                    : 'h-11 px-4 text-xs',
                $props.class,
            )
        "
    >
        <slot />
    </th>
</template>
