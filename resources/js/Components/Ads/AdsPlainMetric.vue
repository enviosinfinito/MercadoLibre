<script setup lang="ts">
import { computed, ref } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    value?: string | number | null
    source?: 'ml' | 'local' | 'ours'
    plain?: string
    why?: string
    how?: string
    /** Compact KPI tile vs inline. */
    size?: 'md' | 'sm'
  }>(),
  {
    source: 'ours',
    size: 'md',
  },
)

const open = ref(false)

const sourceMeta = computed(() => {
  if (props.source === 'ml') {
    return { label: 'Mercado Ads', class: 'bg-sky-100 text-sky-800' }
  }
  if (props.source === 'local') {
    return { label: 'Tus ventas', class: 'bg-slate-200 text-slate-800' }
  }
  return { label: 'Nuestro cálculo', class: 'bg-teal-100 text-teal-800' }
})

const hasExplain = computed(() => Boolean(props.plain || props.why || props.how))
</script>

<template>
  <div
    :class="
      size === 'sm'
        ? 'p-0'
        : 'rounded-lg border border-slate-200 bg-white p-3'
    "
  >
    <button
      type="button"
      class="w-full text-left"
      :disabled="!hasExplain"
      @click="hasExplain && (open = !open)"
    >
      <div class="flex flex-wrap items-center gap-1.5">
        <span
          class="rounded px-1 py-0.5 text-[9px] font-semibold uppercase"
          :class="sourceMeta.class"
        >
          {{ sourceMeta.label }}
        </span>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ label }}</p>
      </div>
      <p
        class="mt-1 font-semibold tabular-nums text-slate-900"
        :class="size === 'sm' ? 'text-sm' : 'text-base'"
      >
        <slot>{{ value ?? '—' }}</slot>
      </p>
      <p v-if="hasExplain && !open" class="mt-0.5 text-xs text-slate-500">
        Tocá para entender este número
      </p>
    </button>
    <div
      v-if="open && hasExplain"
      class="mt-2 space-y-1 border-t border-slate-200/80 pt-2 text-xs text-slate-600"
    >
      <p v-if="plain"><span class="font-semibold text-slate-800">Qué es:</span> {{ plain }}</p>
      <p v-if="why"><span class="font-semibold text-slate-800">Por qué importa:</span> {{ why }}</p>
      <p v-if="how"><span class="font-semibold text-slate-800">De dónde sale:</span> {{ how }}</p>
    </div>
  </div>
</template>
