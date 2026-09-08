<script setup lang="ts">
import Badge from '@/Components/ui/Badge.vue';
import { computed } from 'vue';

const props = defineProps<{
    level?: string | null;
    score?: number | null;
}>();

const label = computed(() => {
    switch (props.level) {
        case 'critical':
            return 'Crítico';
        case 'high':
            return 'Alto';
        case 'attention':
            return 'Atención';
        default:
            return 'Normal';
    }
});

const variant = computed(() => {
    switch (props.level) {
        case 'critical':
            return 'danger';
        case 'high':
            return 'warning';
        case 'attention':
            return 'secondary';
        default:
            return 'outline';
    }
});
</script>

<template>
    <Badge
        :variant="variant as any"
        :title="score != null ? `Risk Score ${score}/100` : undefined"
    >
        {{ label }}
        <span
            v-if="score != null"
            class="ml-1 opacity-80"
        >{{ score }}</span>
    </Badge>
</template>
