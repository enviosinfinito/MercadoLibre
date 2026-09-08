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
    <td
        :class="
            cn(
                'align-middle text-foreground',
                compact ? 'px-3 py-2' : 'p-4',
                $props.class,
            )
        "
    >
        <slot />
    </td>
</template>
