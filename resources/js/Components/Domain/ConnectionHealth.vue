<script setup lang="ts">
import { computed } from 'vue';
import FreshnessBadge from '@/Components/App/FreshnessBadge.vue';
import Badge from '@/Components/ui/Badge.vue';

const props = defineProps<{
    status?: string | null;
    freshnessStatus?: string | null;
    needsReauthorization?: boolean;
    lastSyncedAt?: string | null;
}>();

const healthLabel = computed(() => {
    if (props.needsReauthorization) {
        return 'Needs reauth';
    }
    return props.status ?? 'unknown';
});

const healthVariant = computed(() => {
    if (props.needsReauthorization) {
        return 'danger' as const;
    }
    switch ((props.status ?? '').toLowerCase()) {
        case 'active':
        case 'connected':
            return 'success' as const;
        case 'pending':
            return 'warning' as const;
        case 'error':
        case 'disconnected':
            return 'danger' as const;
        default:
            return 'muted' as const;
    }
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Badge :variant="healthVariant" class="capitalize">{{ healthLabel }}</Badge>
        <FreshnessBadge v-if="freshnessStatus" :status="freshnessStatus" />
        <span v-if="lastSyncedAt" class="text-xs text-muted-foreground">
            Synced {{ lastSyncedAt }}
        </span>
    </div>
</template>
