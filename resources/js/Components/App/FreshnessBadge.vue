<script setup lang="ts">
import { computed } from 'vue';
import Badge from '@/Components/ui/Badge.vue';

const props = withDefaults(
    defineProps<{
        status?: string | null;
        label?: string;
    }>(),
    {
        status: null,
        label: '',
    },
);

const normalized = computed(() => (props.status ?? 'unknown').toLowerCase());

const variant = computed(() => {
    switch (normalized.value) {
        case 'fresh':
        case 'ok':
        case 'healthy':
        case 'active':
            return 'success' as const;
        case 'stale':
        case 'degraded':
        case 'warning':
            return 'warning' as const;
        case 'expired':
        case 'error':
        case 'failed':
            return 'danger' as const;
        default:
            return 'muted' as const;
    }
});

const display = computed(
    () => props.label || props.status || 'unknown',
);
</script>

<template>
    <Badge :variant="variant" class="gap-1.5 capitalize">
        <span
            class="h-1.5 w-1.5 rounded-full"
            :class="{
                'bg-emerald-500': variant === 'success',
                'bg-amber-500': variant === 'warning',
                'bg-red-500': variant === 'danger',
                'bg-slate-400': variant === 'muted',
            }"
        />
        {{ display }}
    </Badge>
</template>
