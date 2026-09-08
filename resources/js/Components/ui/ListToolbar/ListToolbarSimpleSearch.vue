<template>
  <section
    class="w-full overflow-hidden rounded-2xl border border-neutral-200/80 bg-white p-2 shadow-[0_2px_12px_rgba(0,0,0,0.05)] md:h-10 md:min-h-10 md:max-h-10 md:p-0 md:shadow-[0_1px_3px_rgba(0,0,0,0.04)]"
  >
    <div class="relative md:flex md:h-full md:items-center">
      <input
        :value="modelValue"
        type="search"
        :placeholder="placeholder"
        autocomplete="off"
        class="block w-full rounded-xl border border-neutral-200 bg-neutral-50/50 py-2.5 pl-10 pr-9 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-[rgb(var(--brand-primary-rgb)/0.35)] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[rgb(var(--brand-primary-rgb)/0.15)] md:h-10 md:rounded-none md:border-0 md:bg-transparent md:py-0 md:pl-11 md:pr-10 md:shadow-none md:focus:border-0 md:focus:bg-transparent md:focus:ring-0"
        @input="onInput"
      >
      <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400 md:left-4" />
      <button
        v-if="modelValue"
        type="button"
        class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-neutral-400 transition hover:text-neutral-600 md:right-3"
        aria-label="Limpiar búsqueda"
        @click="$emit('clear')"
      >
        <X class="h-4 w-4" />
      </button>
      <div
        v-if="visibleCount != null && modelValue"
        class="pointer-events-none absolute inset-y-0 right-10 flex items-center text-xs tabular-nums text-neutral-500 md:right-12"
      >
        {{ visibleCount }}
      </div>
    </div>
  </section>
</template>

<script setup>
import { Search, X } from 'lucide-vue-next'

defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Buscar…' },
  visibleCount: { type: [Number, String], default: null },
})

const emit = defineEmits(['update:modelValue', 'search', 'clear'])

function onInput(event) {
  const value = event.target.value
  emit('update:modelValue', value)
  emit('search', value)
}
</script>
