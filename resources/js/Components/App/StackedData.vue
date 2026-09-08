<script setup lang="ts">
import { computed } from 'vue';
import { formatByKind } from '@/lib/formatDisplayText';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        primary?: string | null;
        secondary?: string | null;
        primaryKind?: string | null;
        secondaryKind?: string | null;
        empty?: string;
        class?: string;
        primaryClass?: string;
        secondaryClass?: string;
    }>(),
    {
        primary: null,
        secondary: null,
        primaryKind: 'label',
        secondaryKind: null,
        empty: '—',
    },
);

const primaryText = computed(() =>
    formatByKind(props.primary, props.primaryKind ?? 'label'),
);

const secondaryText = computed(() => {
    const value = props.secondary;
    if (value == null || String(value).trim() === '') return '';
    return formatByKind(value, props.secondaryKind ?? 'code');
});

const showEmpty = computed(
    () => !primaryText.value && !secondaryText.value,
);
</script>

<template>
    <div
        :class="
            cn(
                'min-w-0 leading-tight',
                showEmpty ? 'text-[12px] text-muted-foreground' : undefined,
                $props.class,
            )
        "
    >
        <template v-if="showEmpty">
            {{ empty }}
        </template>
        <template v-else>
            <div
                :class="
                    cn(
                        'truncate text-[12px] font-medium tracking-tight text-slate-800',
                        primaryClass,
                    )
                "
            >
                {{ primaryText || empty }}
            </div>
            <div
                v-if="secondaryText"
                :class="
                    cn(
                        'truncate text-[10px] text-muted-foreground',
                        secondaryClass,
                    )
                "
            >
                {{ secondaryText }}
            </div>
        </template>
    </div>
</template>
