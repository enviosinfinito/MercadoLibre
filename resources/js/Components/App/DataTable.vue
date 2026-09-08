<script setup lang="ts">
import EmptyState from '@/Components/App/EmptyState.vue';
import Card from '@/Components/ui/Card.vue';
import Table from '@/Components/ui/Table.vue';
import { cn } from '@/lib/utils';
import { computed, provide } from 'vue';

const props = withDefaults(
    defineProps<{
        emptyTitle?: string;
        emptyDescription?: string;
        isEmpty?: boolean;
        compact?: boolean;
        /** Fija el thead al scrollear la página (bajo AppHeader). */
        stickyHead?: boolean;
    }>(),
    {
        compact: false,
        stickyHead: false,
    },
);

provide(
    'dataTableCompact',
    computed(() => props.compact),
);
</script>

<template>
    <Card
        :class="
            cn(
                stickyHead ? 'overflow-visible' : 'overflow-hidden',
                compact &&
                    'rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]',
            )
        "
        content-class="p-0"
    >
        <EmptyState
            v-if="isEmpty"
            :title="emptyTitle ?? 'No results'"
            :description="emptyDescription"
        >
            <template v-if="$slots.empty" #actions>
                <slot name="empty" />
            </template>
        </EmptyState>
        <Table
            v-else
            :sticky-head="stickyHead"
            :class="compact ? 'text-[12px]' : undefined"
        >
            <template v-if="$slots.head" #head>
                <slot name="head" />
            </template>
            <slot />
        </Table>
        <div
            v-if="$slots.footer && !isEmpty"
            :class="
                cn(
                    'border-t border-border',
                    compact ? 'px-3 py-2' : 'px-4 py-3',
                )
            "
        >
            <slot name="footer" />
        </div>
    </Card>
</template>
