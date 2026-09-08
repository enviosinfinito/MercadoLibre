<script setup lang="ts">
import Badge from '@/Components/ui/Badge.vue';

withDefaults(
    defineProps<{
        label: string;
        variant?: 'success' | 'warning' | 'danger' | 'secondary' | 'muted' | 'default' | 'outline';
        code?: string | null;
        compact?: boolean;
    }>(),
    {
        variant: 'secondary',
        code: null,
        compact: false,
    },
);
</script>

<template>
    <span class="inline-flex flex-col items-start gap-0.5">
        <Badge
            :variant="variant"
            :class="compact ? 'h-5 rounded-full px-1.5 py-0 text-[10px] font-medium' : undefined"
        >
            {{ label }}
        </Badge>
        <span
            v-if="code && !compact"
            class="font-mono text-[10px] text-muted-foreground"
        >
            {{ code }}
        </span>
    </span>
</template>
