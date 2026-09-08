<script setup>
import { computed } from 'vue';
import { connectionFilterLabel, providerLabel } from '@/lib/connectionLabel';
import { connectionSurfaceStyle, resolveConnectionColor } from '@/lib/connectionColor';
import { cn } from '@/lib/utils';

const props = defineProps({
    connection: { type: Object, default: null },
    color: { type: String, default: null },
    label: { type: String, default: null },
    /** Show account name only (no "Mercado Libre ·") */
    accountOnly: { type: Boolean, default: true },
    compact: { type: Boolean, default: true },
    class: { type: String, default: '' },
});

const resolvedColor = computed(() =>
    resolveConnectionColor(props.color ?? props.connection?.color),
);

const surfaceStyle = computed(() => connectionSurfaceStyle(resolvedColor.value));

const displayLabel = computed(() => {
    if (props.label) return props.label;
    const c = props.connection;
    if (!c) return '—';
    if (props.accountOnly) {
        return c.display_name?.trim() || c.external_user_id || providerLabel(c.provider);
    }
    return connectionFilterLabel(c);
});
</script>

<template>
    <span
        :style="surfaceStyle"
        :title="displayLabel"
        :class="
            cn(
                'connection-chip inline-flex max-w-full items-center gap-1 rounded-full border font-medium',
                compact
                    ? 'h-5 px-1.5 text-[10px]'
                    : 'h-6 px-2 text-[11px]',
                props.class,
            )
        "
    >
        <span
            class="size-1.5 shrink-0 rounded-full"
            :style="{ backgroundColor: 'var(--conn)' }"
            aria-hidden="true"
        />
        <span class="truncate">{{ displayLabel }}</span>
    </span>
</template>

<style scoped>
.connection-chip {
    color: var(--conn-fg);
    background: var(--conn-chip);
    border-color: var(--conn-border);
}
</style>
