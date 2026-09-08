<template>
  <SlideOverShell
    :show="show"
    side="right"
    :show-close-button="showCloseButton"
    :z-index="zIndex"
    :accessibility-title="title"
    accessibility-description="Ajusta los filtros del listado y aplica los cambios."
    fill-height
    :compact-header="!minimalHeader"
    :close-only-header="minimalHeader"
    @close="$emit('close')"
  >
    <template v-if="!minimalHeader" #header>
      <div class="flex min-w-0 items-center gap-2">
        <div
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--brand-primary)] shadow-sm"
          aria-hidden="true"
        >
          <Filter class="h-3.5 w-3.5 text-white" />
        </div>
        <h2 class="min-w-0 truncate text-sm font-semibold tracking-tight text-[var(--brand-primary)]">
          {{ title }}
        </h2>
        <slot name="header-subtitle" />
      </div>
    </template>

    <div class="flex min-h-0 flex-1 flex-col overflow-hidden bg-[#fafcfb]">
      <div
        v-if="$slots['active-bar']"
        class="shrink-0 border-b border-neutral-200/80 bg-white/95 px-3 py-2.5 backdrop-blur-sm sm:px-4"
      >
        <slot name="active-bar" />
      </div>
      <div
        class="filters-slide-over__scroll min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-3 sm:px-4 sm:py-4 md:px-5 md:py-5"
        :class="{ 'pt-2': minimalHeader }"
        @scroll="$emit('content-scroll')"
      >
        <slot />
      </div>
    </div>

    <template v-if="$slots.footer" #footer>
      <div class="filters-slide-over__footer">
        <slot name="footer" />
      </div>
    </template>
  </SlideOverShell>
</template>

<script setup>
import { Filter } from 'lucide-vue-next'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'

defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: 'Filtros' },
  minimalHeader: { type: Boolean, default: true },
  showCloseButton: { type: Boolean, default: true },
  zIndex: { type: [Number, String], default: null },
})

defineEmits(['close', 'content-scroll'])
</script>

<style scoped>
@media (min-width: 768px) {
  .filters-slide-over__footer :deep(button) {
    min-height: clamp(2.35rem, 3.2vh, 2.875rem);
    font-size: clamp(0.8125rem, 0.95vh + 0.25rem, 0.9375rem);
  }
}
</style>
