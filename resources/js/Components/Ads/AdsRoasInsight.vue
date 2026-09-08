<script setup lang="ts">
import { computed } from 'vue'
import MoneyText from '@/Components/App/MoneyText.vue'

const props = defineProps<{
  insight: Record<string, any> | null | undefined
  currency?: string
  /** When true, drop outer chrome (used inside nested slide). */
  embedded?: boolean
}>()

const emit = defineEmits<{
  'focus-chart': []
}>()

const currency = computed(() => props.currency || 'MXN')
const insight = computed(() => props.insight || null)
const embedded = computed(() => Boolean(props.embedded))

const zoneMeta = computed(() => {
  const zone = insight.value?.zone
  if (zone === 'above') {
    return {
      label: 'En objetivo',
      class: 'bg-emerald-100 text-emerald-900 border-emerald-200',
      bar: 'bg-emerald-500',
      panel: 'border-emerald-200 bg-emerald-50/40',
    }
  }
  if (zone === 'near') {
    return {
      label: 'Casi',
      class: 'bg-amber-100 text-amber-900 border-amber-200',
      bar: 'bg-amber-500',
      panel: 'border-amber-200 bg-amber-50/40',
    }
  }
  if (zone === 'below') {
    return {
      label: 'Bajo objetivo',
      class: 'bg-rose-100 text-rose-900 border-rose-200',
      bar: 'bg-rose-500',
      panel: 'border-rose-200 bg-rose-50/40',
    }
  }
  return {
    label: 'Sin lectura',
    class: 'bg-slate-100 text-slate-700 border-slate-200',
    bar: 'bg-slate-400',
    panel: 'border-slate-200 bg-slate-50/50',
  }
})

const progressPct = computed(() => {
  const pct = Number(insight.value?.pct_of_target)
  if (!Number.isFinite(pct) || pct <= 0) return 0
  return Math.min(100, Math.round(pct * 100))
})

function formatRoas(v: number | null | undefined) {
  if (v == null || !Number.isFinite(Number(v))) return '—'
  return `${Number(v).toFixed(2)}x`
}

function formatPct(v: number | null | undefined, digits = 1) {
  if (v == null || !Number.isFinite(Number(v))) return '—'
  return `${(Number(v) * 100).toFixed(digits)}%`
}

const gapLabel = computed(() => {
  const gap = insight.value?.gap
  if (gap == null || !Number.isFinite(Number(gap))) return null
  const n = Number(gap)
  if (n === 0) return 'Exacto al objetivo'
  if (n > 0) return `+${n.toFixed(2)}x por encima del objetivo`
  return `${n.toFixed(2)}x por debajo del objetivo`
})
</script>

<template>
  <div
    v-if="insight"
    class="overflow-hidden"
    :class="embedded ? '' : ['rounded-lg border', zoneMeta.panel]"
  >
    <div
      class="flex flex-wrap items-start justify-between gap-3"
      :class="embedded ? 'pb-1' : 'px-3.5 pt-3.5'"
    >
      <div class="min-w-0">
        <div v-if="!embedded" class="flex flex-wrap items-center gap-2">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-600">
            ¿Cuánto te rinden los ads?
          </h4>
          <span
            class="rounded-full border px-2 py-0.5 text-[10px] font-semibold"
            :class="zoneMeta.class"
          >
            {{ zoneMeta.label }}
          </span>
          <span class="rounded-full bg-teal-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-teal-800">
            Nuestro
          </span>
        </div>
        <p class="text-sm text-slate-800" :class="embedded ? '' : 'mt-1.5'">
          {{ insight.plain }}
        </p>
        <p v-if="insight.why_target" class="mt-1 text-[11px] text-slate-500">
          {{ insight.why_target }}
        </p>
      </div>
      <div class="text-right">
        <p class="text-[10px] uppercase tracking-wide text-slate-500">Actual</p>
        <p class="text-2xl font-semibold tabular-nums tracking-tight text-slate-900">
          {{ formatRoas(insight.actual) }}
        </p>
        <p class="mt-0.5 text-[11px] text-slate-500">
          objetivo {{ formatRoas(insight.target) }}
        </p>
      </div>
    </div>

    <div :class="embedded ? 'pt-3' : 'px-3.5 pt-3'">
      <div class="flex items-center justify-between text-[11px] text-slate-500">
        <span>0x</span>
        <span v-if="gapLabel" class="font-medium text-slate-700">{{ gapLabel }}</span>
        <span>meta {{ formatRoas(insight.target) }}</span>
      </div>
      <div class="relative mt-1.5 h-2.5 overflow-hidden rounded-full bg-slate-100 ring-1 ring-slate-200/80">
        <div
          class="absolute inset-y-0 left-0 rounded-full transition-all"
          :class="zoneMeta.bar"
          :style="{ width: `${progressPct}%` }"
        />
        <div
          class="absolute inset-y-0 w-0.5 bg-slate-800/70"
          style="left: 100%; transform: translateX(-1px)"
          title="Objetivo"
        />
      </div>
      <p class="mt-1.5 text-[11px] text-slate-600">
        Vas al
        <span class="font-semibold tabular-nums">{{ progressPct }}%</span>
        del ROAS objetivo
        <span v-if="insight.pct_of_target != null" class="text-slate-400">
          ({{ Number(insight.pct_of_target).toFixed(2) }}× el target)
        </span>
      </p>
    </div>

    <div class="mt-3 grid gap-px border-y border-slate-200 bg-slate-200/60 sm:grid-cols-3">
      <div class="bg-white px-3 py-2.5">
        <p class="text-[10px] uppercase tracking-wide text-slate-400">Fórmula</p>
        <p class="mt-1 text-[11px] text-slate-500">{{ insight.formula?.expression }}</p>
        <p class="mt-1 text-xs tabular-nums text-slate-800">
          <MoneyText :amount="insight.formula?.revenue" :currency="currency" />
          <span class="text-slate-400"> ÷ </span>
          <MoneyText :amount="insight.formula?.cost" :currency="currency" />
        </p>
      </div>
      <div class="bg-white px-3 py-2.5">
        <p class="text-[10px] uppercase tracking-wide text-slate-400">% del ingreso en ads</p>
        <p class="mt-1 text-sm font-semibold tabular-nums text-slate-900">
          {{ formatPct(insight.acos?.actual) }}
          <span class="text-xs font-normal text-slate-500">
            / máx {{ formatPct(insight.acos?.target) }}
          </span>
        </p>
        <p v-if="insight.acos?.plain" class="mt-1 text-[11px] leading-snug text-slate-600">
          {{ insight.acos.plain }}
        </p>
      </div>
      <div class="bg-white px-3 py-2.5">
        <p class="text-[10px] uppercase tracking-wide text-slate-400">Días vs objetivo</p>
        <p class="mt-1 text-sm font-semibold tabular-nums text-slate-900">
          <span class="text-emerald-700">{{ insight.days?.above_target ?? 0 }}</span>
          <span class="mx-0.5 font-normal text-slate-400">ok</span>
          <span class="text-rose-700">{{ insight.days?.below_target ?? 0 }}</span>
          <span class="ml-0.5 font-normal text-slate-400">bajo</span>
        </p>
        <p class="mt-1 text-[11px] text-slate-600">
          {{ formatPct(insight.days?.on_target_pct, 0) }} de los días con gasto cumplieron meta
        </p>
      </div>
    </div>

    <div class="space-y-2" :class="embedded ? 'pt-3' : 'px-3.5 py-3'">
      <div
        v-if="insight.target_unreliable"
        class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-950"
      >
        No usamos esta meta para asustarte: faltan datos de ganancia. La meta es provisional.
      </div>

      <p class="text-xs leading-relaxed text-slate-700">{{ insight.verdict }}</p>

      <div
        v-if="insight.zone === 'below' || insight.zone === 'near'"
        class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-700"
      >
        <p class="font-semibold text-slate-800">Para llegar al objetivo con el gasto actual</p>
        <ul class="mt-1.5 list-disc space-y-1 pl-4">
          <li v-if="Number(insight.to_hit_target?.extra_revenue_needed) > 0">
            Necesitás
            <MoneyText
              :amount="insight.to_hit_target.extra_revenue_needed"
              :currency="currency"
              class="font-semibold"
            />
            más de revenue atribuido (sin subir inversión).
          </li>
          <li v-if="Number(insight.to_hit_target?.spend_cut_needed) > 0">
            O bajar el gasto en
            <MoneyText
              :amount="insight.to_hit_target.spend_cut_needed"
              :currency="currency"
              class="font-semibold"
            />
            (tope sano con el revenue actual:
            <MoneyText
              :amount="insight.to_hit_target.max_spend_at_current_revenue"
              :currency="currency"
            />).
          </li>
          <li v-else-if="insight.zone === 'near'">
            Estás cerca: un poco más de conversión o menos CPC suele cerrar el gap.
          </li>
        </ul>
      </div>

      <div
        v-else-if="insight.zone === 'above'"
        class="rounded-md border border-emerald-200 bg-emerald-50/60 px-3 py-2 text-[11px] text-emerald-900"
      >
        Hay margen para sostener o incluso probar un poco más de presupuesto sin romper la meta.
      </div>

      <p class="text-[11px] leading-snug text-slate-500">{{ insight.why_target }}</p>

      <button
        type="button"
        class="text-[11px] font-medium text-teal-700 underline decoration-dotted hover:text-teal-900"
        @click="emit('focus-chart')"
      >
        Ver evolución diaria →
      </button>
    </div>
  </div>
</template>
