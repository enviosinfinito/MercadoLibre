<template>
  <div class="flex h-10 items-stretch gap-1.5">
    <button
      type="button"
      class="inline-flex items-center gap-1.5 rounded-xl border border-neutral-200/80 bg-white px-3 text-sm font-medium text-neutral-700 shadow-sm hover:bg-neutral-50"
      title="Abrir filtros"
      @click="$emit('open-filters')"
    >
      <Filter class="h-3.5 w-3.5 shrink-0 text-neutral-500" aria-hidden="true" />
      <span>Filtros</span>
      <span
        v-if="filterCount > 0"
        class="ml-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-[var(--brand-primary)] px-1 text-[10px] font-bold text-white"
      >
        {{ filterCount }}
      </span>
    </button>
    <slot name="more-actions" />
  </div>
</template>

<script setup>
import { Filter } from 'lucide-vue-next'

defineProps({
  filterCount: { type: Number, default: 0 },
})

defineEmits(['open-filters'])
</script>
