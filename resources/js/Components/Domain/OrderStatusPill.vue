<script setup lang="ts">
import { computed } from 'vue';
import Badge from '@/Components/ui/Badge.vue';

const props = withDefaults(
    defineProps<{
        status?: string | null;
        outcome?: string | null;
        compact?: boolean;
        /** When true and outcome is terminal, show fulfillment status as secondary text. */
        showFulfillmentHint?: boolean;
    }>(),
    {
        status: 'unknown',
        outcome: null,
        compact: false,
        showFulfillmentHint: false,
    },
);

const normalizedStatus = computed(() => (props.status ?? 'unknown').toLowerCase());
const normalizedOutcome = computed(() => (props.outcome ?? '').toLowerCase());

const fulfillmentLabel = computed(() => {
    switch (normalizedStatus.value) {
        case 'paid':
            return 'Pagada';
        case 'pending':
            return 'Pendiente';
        case 'processing':
            return 'En proceso';
        case 'confirmed':
            return 'Confirmada';
        case 'shipped':
        case 'in_transit':
            return 'En tránsito';
        case 'delivered':
            return 'Entregada';
        case 'completed':
            return 'Completada';
        case 'cancelled':
        case 'canceled':
            return 'Cancelada';
        case 'refunded':
            return 'Reembolsada';
        case 'unknown':
            return 'Desconocido';
        default:
            return props.status ?? 'Desconocido';
    }
});

const outcomeLabel = computed(() => {
    switch (normalizedOutcome.value) {
        case 'returned':
            return 'Devuelta';
        case 'refunded':
            return 'Reembolsada';
        case 'partial_refunded':
            return 'Reembolso parcial';
        case 'claim_open':
            return 'Reclamo';
        default:
            return null;
    }
});

const isTerminalOutcome = computed(() =>
    ['returned', 'refunded', 'partial_refunded'].includes(normalizedOutcome.value),
);

const label = computed(() => {
    if (isTerminalOutcome.value && outcomeLabel.value) {
        return outcomeLabel.value;
    }
    return fulfillmentLabel.value;
});

const variant = computed(() => {
    if (normalizedOutcome.value === 'returned' || normalizedOutcome.value === 'refunded') {
        return 'danger' as const;
    }
    if (normalizedOutcome.value === 'partial_refunded' || normalizedOutcome.value === 'claim_open') {
        return 'warning' as const;
    }
    switch (normalizedStatus.value) {
        case 'paid':
        case 'completed':
        case 'delivered':
            return 'success' as const;
        case 'pending':
        case 'processing':
        case 'confirmed':
            return 'warning' as const;
        case 'cancelled':
        case 'canceled':
        case 'refunded':
            return 'danger' as const;
        default:
            return 'secondary' as const;
    }
});
</script>

<template>
    <span class="inline-flex flex-col items-end gap-0.5">
        <Badge
            :variant="variant"
            :class="compact ? 'h-5 rounded-full px-1.5 py-0 text-[10px] font-medium' : undefined"
        >
            {{ label }}
        </Badge>
        <span
            v-if="showFulfillmentHint && isTerminalOutcome && fulfillmentLabel"
            class="text-[10px] text-muted-foreground"
        >
            antes: {{ fulfillmentLabel }}
        </span>
        <Badge
            v-else-if="!isTerminalOutcome && normalizedOutcome === 'claim_open'"
            variant="warning"
            class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
        >
            Reclamo
        </Badge>
    </span>
</template>
