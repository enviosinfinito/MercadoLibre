<script setup lang="ts">
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        total: number
        showing: number
        hasMore: boolean
        from?: number | null
        to?: number | null
        singularLabel?: string
        pluralLabel?: string
        compact?: boolean
    }>(),
    {
        compact: false,
    },
)
</script>

<template>
    <div
        v-if="total > 0"
        :class="
            cn(
                'flex items-center',
                compact ? 'mt-4 gap-3' : 'mt-10 gap-5',
            )
        "
    >
        <span
            class="flex-1 border-t border-slate-200"
            aria-hidden="true"
        />
        <span
            :class="
                cn(
                    'whitespace-nowrap text-neutral-500',
                    compact ? 'text-[11px]' : 'text-[13px]',
                )
            "
        >
            <span class="font-semibold tabular-nums">{{ total.toLocaleString() }}</span>
            {{ total === 1 ? (singularLabel || 'ítem') : (pluralLabel || 'ítems') }}
            <template v-if="hasMore && showing > 0">
                · mostrando
                <span class="font-semibold tabular-nums">{{ showing.toLocaleString() }}</span>
                (scroll para más)
            </template>
            <template v-else-if="from != null && to != null">
                · mostrando {{ from }} a {{ to }}
            </template>
        </span>
        <span
            class="flex-1 border-t border-slate-200"
            aria-hidden="true"
        />
    </div>
</template>
