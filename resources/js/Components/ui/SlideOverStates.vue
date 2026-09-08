<template>
  <div
    v-if="loading"
    class="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-16 text-neutral-500"
  >
    <div
      class="h-10 w-10 animate-spin rounded-full border-2 border-brand/20 border-t-brand"
      aria-hidden="true"
    />
    <p class="text-sm font-medium text-neutral-700">{{ loadingMessage }}</p>
  </div>

  <div
    v-else-if="error"
    class="flex flex-1 flex-col items-center justify-center gap-4 px-6 py-16 text-center"
  >
    <p class="text-sm text-neutral-700">{{ error }}</p>
    <button
      v-if="showRetry"
      type="button"
      class="text-sm font-medium text-brand hover:text-brand-hover"
      @click="$emit('retry')"
    >
      Reintentar
    </button>
  </div>

  <div
    v-else
    :class="contentClass"
  >
    <slot />
  </div>
</template>

<script setup>
defineProps({
  loading: { type: Boolean, default: false },
  error: { type: String, default: null },
  loadingMessage: { type: String, default: 'Cargando…' },
  showRetry: { type: Boolean, default: true },
  contentClass: {
    type: String,
    default: 'flex min-h-0 flex-1 flex-col overflow-hidden',
  },
})

defineEmits(['retry'])
</script>
