<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  timeline: {
    type: Object,
    default: () => ({ points: [], milestones: [] }),
  },
})

const selected = ref(null)

const points = computed(() => props.timeline?.points ?? [])
const milestones = computed(() => props.timeline?.milestones ?? [])

const levelColor = (level) => {
  if (!level) return '#94a3b8'
  if (level.includes('5_green') || level === 'green') return '#16a34a'
  if (level.includes('4_light_green') || level === 'light_green') return '#4ade80'
  if (level.includes('yellow')) return '#eab308'
  if (level.includes('orange')) return '#f97316'
  if (level.includes('red')) return '#ef4444'
  return '#94a3b8'
}

const formatDate = (iso) => {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString('es-MX', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}

const formatPct = (rate) => {
  if (rate == null || Number.isNaN(Number(rate))) return '—'
  return `${(Number(rate) * 100).toFixed(2)}%`
}

const openMilestone = (m) => {
  selected.value = selected.value?.captured_at === m.captured_at ? null : m
}

const pointLeft = (index, total) => {
  if (total <= 1) return 50
  return (index / (total - 1)) * 100
}
</script>

<template>
  <div class="space-y-2">
    <div class="flex items-center justify-between gap-2">
      <h4 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
        Evolución
      </h4>
      <span class="text-[10px] text-slate-400">{{ points.length }} muestras</span>
    </div>

    <div
      v-if="points.length === 0"
      class="rounded-lg border border-dashed border-slate-200 bg-slate-50/80 px-3 py-3 text-[11px] leading-relaxed text-slate-500"
    >
      La evolución se construye a partir de hoy; el primer punto aparece tras el próximo sync de
      reputación.
    </div>

    <div v-else class="overflow-x-auto pb-1">
      <div class="relative min-w-[280px] px-2 pt-3 pb-8">
        <div class="absolute left-2 right-2 top-[22px] h-0.5 rounded-full bg-slate-200" />
        <div
          class="absolute left-2 top-[22px] h-0.5 rounded-full"
          :style="{
            width: 'calc(100% - 1rem)',
            background: `linear-gradient(90deg, ${points
              .map((p) => levelColor(p.level_id))
              .join(', ')})`,
          }"
        />

        <div class="relative h-10">
          <button
            v-for="(point, index) in points"
            :key="`${point.capture_date}-${index}`"
            type="button"
            class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2"
            :style="{ left: `${pointLeft(index, points.length)}%` }"
            :title="formatDate(point.captured_at)"
            @click="point.is_milestone && openMilestone(milestones.find((m) => m.captured_at === point.captured_at) || point)"
          >
            <span
              v-if="point.is_milestone"
              class="flex size-5 items-center justify-center rounded-full border-2 border-white shadow-sm ring-1 ring-black/5 transition hover:scale-110"
              :style="{ backgroundColor: levelColor(point.level_id) }"
            />
            <span
              v-else
              class="block size-1.5 rounded-full bg-slate-400/70"
            />
          </button>
        </div>

        <div class="pointer-events-none absolute inset-x-0 bottom-0 flex justify-between px-1 text-[9px] text-slate-400">
          <span>{{ formatDate(points[0]?.captured_at) }}</span>
          <span>{{ formatDate(points[points.length - 1]?.captured_at) }}</span>
        </div>
      </div>
    </div>

    <div
      v-if="selected"
      class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-[11px] text-slate-700 shadow-sm"
    >
      <div class="flex flex-wrap items-center gap-1.5">
        <span
          class="inline-flex size-2.5 rounded-full"
          :style="{ backgroundColor: levelColor(selected.level_id) }"
        />
        <span class="font-semibold text-slate-900">{{ selected.label || 'Hito' }}</span>
        <span class="text-slate-400">·</span>
        <span class="text-slate-500">{{ formatDate(selected.captured_at) }}</span>
      </div>
      <dl class="mt-2 grid grid-cols-3 gap-2">
        <div>
          <dt class="text-[10px] text-slate-400">Reclamos</dt>
          <dd class="font-medium tabular-nums">{{ formatPct(selected.claims_rate) }}</dd>
        </div>
        <div>
          <dt class="text-[10px] text-slate-400">Cancelaciones</dt>
          <dd class="font-medium tabular-nums">{{ formatPct(selected.cancellations_rate) }}</dd>
        </div>
        <div>
          <dt class="text-[10px] text-slate-400">Despacho tardío</dt>
          <dd class="font-medium tabular-nums">{{ formatPct(selected.delayed_handling_rate) }}</dd>
        </div>
      </dl>
      <p
        v-if="selected.power_seller_status"
        class="mt-1.5 text-[10px] text-slate-500"
      >
        Líder {{ selected.power_seller_status }}
      </p>
    </div>

    <ul v-if="milestones.length" class="space-y-1">
      <li
        v-for="m in milestones.slice().reverse().slice(0, 5)"
        :key="m.captured_at + (m.kind || '')"
      >
        <button
          type="button"
          class="flex w-full items-center gap-2 rounded-md px-1.5 py-1 text-left text-[11px] hover:bg-slate-50"
          @click="openMilestone(m)"
        >
          <span
            class="size-2 shrink-0 rounded-full"
            :style="{ backgroundColor: levelColor(m.level_id) }"
          />
          <span class="min-w-0 flex-1 truncate font-medium text-slate-700">{{ m.label }}</span>
          <span class="shrink-0 text-[10px] text-slate-400">{{ formatDate(m.captured_at) }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>
