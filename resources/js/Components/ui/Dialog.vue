<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        open?: boolean;
        title?: string;
        class?: string;
    }>(),
    {
        open: false,
        title: '',
    },
);

const emit = defineEmits<{
    close: [];
    'update:open': [value: boolean];
}>();

const close = () => {
    emit('update:open', false);
    emit('close');
};

const onEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && props.open) {
        close();
    }
};

watch(
    () => props.open,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
    },
);

onMounted(() => document.addEventListener('keydown', onEscape));
onUnmounted(() => {
    document.removeEventListener('keydown', onEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
            <div class="absolute inset-0 bg-slate-900/50" @click="close" />
            <div
                :class="
                    cn(
                        'relative z-10 w-full max-w-lg rounded-lg border border-border bg-card p-6 shadow-lg',
                        $props.class,
                    )
                "
                role="dialog"
                aria-modal="true"
            >
                <div v-if="title || $slots.title" class="mb-4 flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-foreground">
                        <slot name="title">{{ title }}</slot>
                    </h2>
                    <button
                        type="button"
                        class="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label="Close"
                        @click="close"
                    >
                        ✕
                    </button>
                </div>
                <slot />
                <div v-if="$slots.footer" class="mt-6 flex justify-end gap-2">
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
