<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import Badge from '@/Components/ui/Badge.vue'
import Card from '@/Components/ui/Card.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import DashboardChart from '@/Components/Dashboard/DashboardChart.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components'
import { Megaphone } from 'lucide-vue-next'

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent, LegendComponent])

const PERIODS = [
  { value: 'last_7_days', label: '7d' },
  { value: 'last_30_days', label: '30d' },
  { value: 'last_90_days', label: '90d' },
  { value: 'this_month', label: 'Mes' },
] as const

const props = withDefaults(
  defineProps<{
    productId: number | null
    period?: string
    connectionIds?: number[]
    active?: boolean
  }>(),
  {
    period: 'last_30_days',
    connectionIds: () => [],
    active: true,
  },
)

const loading = ref(false)
const error = ref<string | null>(null)
const payload = ref<Record<string, any> | null>(null)
const selectedPeriod = ref(props.period || 'last_30_days')

const summary = computed(() => payload.value?.summary || {})
const currency = computed(() => summary.value.currency || 'MXN')
const hasData = computed(() => Boolean(payload.value?.has_data))

function buildUrl(productId: number) {
  const params: Record<string, unknown> = {
    product: productId,
    period: selectedPeriod.value || 'last_30_days',
    group_by: 'campaign',
  }
  if (props.connectionIds?.length) {
    params.connection_ids = props.connectionIds
  }
  return route('products.ads', params)
}

async function load() {
  if (!props.productId) {
    payload.value = null
    return
  }
  loading.value = true
  error.value = null
  try {
    payload.value = await fetchSlidePayload(buildUrl(props.productId), { cache: 'no-store' })
  } catch (e: any) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar publicidad.'
    payload.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => props.period,
  (value) => {
    if (value && value !== selectedPeriod.value) {
      selectedPeriod.value = value
    }
  },
)

watch(
  () => [props.active, props.productId, selectedPeriod.value, props.connectionIds] as const,
  ([active, productId]) => {
    if (active && productId) void load()
    if (!productId) {
      payload.value = null
      error.value = null
    }
  },
  { immediate: true },
)

function formatPct(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(Number(value))) return '—'
  return `${(Number(value) * 100).toFixed(1)}%`
}

function formatRoas(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(Number(value))) return '—'
  return `${Number(value).toFixed(2)}x`
}

const chartOption = computed(() => {
  const series = Array.isArray(payload.value?.series) ? payload.value.series : []
  return {
    tooltip: { trigger: 'axis' },
    legend: { data: ['Inversión', 'Revenue atr.'] },
    grid: { left: 40, right: 16, top: 36, bottom: 24 },
    xAxis: { type: 'category', data: series.map((s: any) => s.label) },
    yAxis: { type: 'value' },
    series: [
      {
        name: 'Inversión',
        type: 'line',
        smooth: true,
        data: series.map((s: any) => s.cost),
        itemStyle: { color: '#0f766e' },
      },
      {
        name: 'Revenue atr.',
        type: 'line',
        smooth: true,
        data: series.map((s: any) => s.attributed_revenue),
        itemStyle: { color: '#0369a1' },
      },
    ],
  }
})
</script>

<template>
  <div class="space-y-4 p-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="opt in PERIODS"
          :key="opt.value"
          type="button"
          class="rounded-md px-2 py-1 text-[11px] font-medium transition"
          :class="
            selectedPeriod === opt.value
              ? 'bg-teal-700 text-white'
              : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
          "
          @click="selectedPeriod = opt.value"
        >
          {{ opt.label }}
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <Link
          v-if="payload?.assistant_url"
          :href="payload.assistant_url"
          class="text-xs font-medium text-teal-700 hover:underline"
        >
          Asistente
        </Link>
        <Link
          v-if="payload?.dashboard_url"
          :href="payload.dashboard_url"
          class="text-xs font-medium text-teal-700 hover:underline"
        >
          Ver en dashboard
        </Link>
      </div>
    </div>

    <div
      v-if="loading"
      class="py-10 text-center text-sm text-slate-500"
    >
      Cargando publicidad…
    </div>
    <div
      v-else-if="error"
      class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"
    >
      {{ error }}
    </div>
    <div
      v-else-if="!productId"
      class="py-10 text-center text-sm text-slate-500"
    >
      Vincula un producto del sistema para ver publicidad.
    </div>
    <div
      v-else-if="!hasData"
      class="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center"
    >
      <Megaphone class="mx-auto h-7 w-7 text-slate-400" />
      <p class="mt-2 text-sm font-medium text-slate-800">Sin inversión en este período</p>
      <p class="mt-1 text-xs text-slate-500">
        Sincroniza Product Ads en la conexión o amplía el período.
      </p>
    </div>
    <template v-else>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        <Card content-class="p-3">
          <p class="text-[11px] text-slate-500">Inversión</p>
          <p class="mt-0.5 text-base font-semibold">
            <MoneyText :amount="summary.cost" :currency="currency" />
          </p>
        </Card>
        <Card content-class="p-3">
          <p class="text-[11px] text-slate-500">ROAS / ACoS</p>
          <p class="mt-0.5 text-base font-semibold tabular-nums">
            {{ formatRoas(summary.roas) }}
            <span class="text-xs font-normal text-slate-500">/ {{ formatPct(summary.acos) }}</span>
          </p>
        </Card>
        <Card content-class="p-3">
          <p class="text-[11px] text-slate-500">Ads en P&L</p>
          <p class="mt-0.5 text-base font-semibold">
            <MoneyText :amount="summary.pnl_ads" :currency="currency" />
          </p>
        </Card>
        <Card content-class="p-3">
          <p class="text-[11px] text-slate-500">Clics · CPC</p>
          <p class="mt-0.5 text-base font-semibold tabular-nums">
            {{ summary.clicks ?? 0 }}
            <span class="text-xs font-normal text-slate-500">
              · <MoneyText :amount="summary.cpc" :currency="currency" />
            </span>
          </p>
        </Card>
        <Card content-class="p-3">
          <p class="text-[11px] text-slate-500">Revenue atr. ML</p>
          <p class="mt-0.5 text-base font-semibold">
            <MoneyText :amount="summary.attributed_revenue" :currency="currency" />
          </p>
        </Card>
        <Card content-class="p-3">
          <p class="text-[11px] text-slate-500">ACoS blended</p>
          <p class="mt-0.5 text-base font-semibold tabular-nums">
            {{ formatPct(summary.blended_acos) }}
          </p>
        </Card>
      </div>

      <Card content-class="p-3">
        <DashboardChart :option="chartOption" height="200px" />
      </Card>

      <div
        v-if="payload?.recommendations?.length"
        class="space-y-2 rounded-md border border-amber-200 bg-amber-50/60 p-3"
      >
        <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-800">
          Recomendaciones
          <span v-if="payload.suggested_target_roas" class="font-normal normal-case text-amber-700">
            · ROAS objetivo ~{{ Number(payload.suggested_target_roas).toFixed(1) }}x
          </span>
        </p>
        <div
          v-for="(rec, idx) in payload.recommendations"
          :key="idx"
          class="text-xs text-amber-950"
        >
          <p class="font-medium">{{ rec.title }}</p>
          <p class="text-amber-800/80">{{ rec.reason }}</p>
        </div>
      </div>

      <div v-if="payload?.by_campaign?.length">
        <div class="mb-1.5 flex items-center gap-2">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Por campaña</h4>
          <Badge variant="secondary">{{ payload.by_campaign.length }}</Badge>
        </div>
        <div class="overflow-hidden rounded-md border border-slate-200">
          <table class="min-w-full text-xs">
            <thead class="bg-slate-50 text-left text-slate-500">
              <tr>
                <th class="px-3 py-2 font-medium">Campaña</th>
                <th class="px-3 py-2 font-medium text-right">Inversión</th>
                <th class="px-3 py-2 font-medium text-right">ROAS</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in payload.by_campaign"
                :key="row.key"
                class="border-t border-slate-100"
              >
                <td class="px-3 py-2 font-medium text-slate-800">{{ row.label }}</td>
                <td class="px-3 py-2 text-right tabular-nums">
                  <MoneyText :amount="row.cost" :currency="currency" />
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ formatRoas(row.roas) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="payload?.by_item?.length > 1">
        <div class="mb-1.5 flex items-center gap-2">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Por publicación</h4>
        </div>
        <div class="overflow-hidden rounded-md border border-slate-200">
          <table class="min-w-full text-xs">
            <thead class="bg-slate-50 text-left text-slate-500">
              <tr>
                <th class="px-3 py-2 font-medium">Ítem</th>
                <th class="px-3 py-2 font-medium text-right">Inversión</th>
                <th class="px-3 py-2 font-medium text-right">ACoS</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in (payload?.by_item ?? [])"
                :key="row.key"
                class="border-t border-slate-100"
              >
                <td class="px-3 py-2 font-medium text-slate-800">{{ row.label }}</td>
                <td class="px-3 py-2 text-right tabular-nums">
                  <MoneyText :amount="row.cost" :currency="currency" />
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ formatPct(row.acos) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="text-[11px] text-slate-500">
        Montos de P&L son estimados (ACoS blended ítem/día). ML no reporta costo de ads por orden.
      </p>
    </template>
  </div>
</template>
