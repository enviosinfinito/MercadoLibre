<script setup lang="ts">
defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <div
            class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-brand-muted text-brand"
        >
            <slot name="icon">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-6 w-6"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.5"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4m16 0H4"
                    />
                </svg>
            </slot>
        </div>
        <h3 class="text-base font-semibold text-slate-900">
            {{ title ?? 'Nothing here yet' }}
        </h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-muted-foreground">
            {{ description }}
        </p>
        <div v-if="$slots.actions" class="mt-4">
            <slot name="actions" />
        </div>
    </div>
</template>
