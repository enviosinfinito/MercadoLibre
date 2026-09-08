<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import DashboardChart from '@/Components/Dashboard/DashboardChart.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import AdsAttributedSalesSlideOver from '@/Components/Ads/AdsAttributedSalesSlideOver.vue'
import AdsPlainMetric from '@/Components/Ads/AdsPlainMetric.vue'
import AdsRoasInsightSlideOver from '@/Components/Ads/AdsRoasInsightSlideOver.vue'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import { useDetailSlide } from '@/composables/useDetailSlide'
import { ADS_ENTITY_DETAIL_SLIDE_OVER_WIDTH_CLASS } from '@/lib/slideOverLayout'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart, LineChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  MarkLineComponent,
  MarkAreaComponent,
} from 'echarts/components'
import { ExternalLink, ChevronDown } from 'lucide-vue-next'
import { ADS_PDP } from '@/lib/adsPdpCopy'

use([
  CanvasRenderer,
  BarChart,
  LineChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  MarkLineComponent,
  MarkAreaComponent,
])

const props = defineProps<{
  show: boolean
  mlItemId: string | null
  connectionId?: number | null
  days?: number
}>()

const emit = defineEmits<{
  close: []
  'approve-proposal': [id: number]
  'select-item': [payload: { mlItemId: string; connectionId?: number | null }]
}>()

const currency = 'MXN'
const salesOpen = ref(false)
const roasOpen = ref(false)
const chartMode = ref<'money' | 'efficiency' | 'traffic'>('efficiency')
const detailTab = ref<'campaigns' | 'listings' | 'daily'>('campaigns')
const chartSectionRef = ref<HTMLElement | null>(null)
const patternDetailsOpen = ref(false)

const { show, loading, error, data, open, close, retry } = useDetailSlide({
  fetchUrl: (id: string) => {
    const params: Record<string, unknown> = {
      mlItemId: id,
      days: props.days || 14,
    }
    if (props.connectionId) params.connection_id = props.connectionId
    return route('ads.assistant.items.show', params)
  },
  cache: 'no-store',
})

watch(
  () => [props.show, props.mlItemId, props.connectionId, props.days] as const,
  ([isOpen, id]) => {
    if (isOpen && id) {
      void open(id)
      chartMode.value = 'efficiency'
      detailTab.value = 'campaigns'
      patternDetailsOpen.value = false
    }
    if (!isOpen) {
      close()
    }
  },
  { immediate: true },
)

const listing = computed(() => data.value?.listing || {})
const kpis = computed(() => data.value?.kpis || {})
const score = computed(() => data.value?.score || null)
const trend = computed(() => data.value?.trend || {})
const seriesRows = computed(() =>
  Array.isArray(data.value?.series) ? (data.value.series as Array<Record<string, any>>) : [],
)
const campaignRows = computed(() =>
  Array.isArray(data.value?.by_campaign)
    ? (data.value.by_campaign as Array<Record<string, any>>)
    : [],
)
const campaignListings = computed(() => {
  const block = data.value?.campaign_listings
  if (!block || typeof block !== 'object') {
    return {
      campaigns: [] as Array<Record<string, any>>,
      listings: [] as Array<Record<string, any>>,
      listing_count: 0,
      other_count: 0,
      current_share: null as number | null,
      total_cost: 0,
      note: '',
    }
  }
  return {
    campaigns: Array.isArray(block.campaigns) ? (block.campaigns as Array<Record<string, any>>) : [],
    listings: Array.isArray(block.listings) ? (block.listings as Array<Record<string, any>>) : [],
    listing_count: Number(block.listing_count || 0),
    other_count: Number(block.other_count || 0),
    current_share: block.current_share == null ? null : Number(block.current_share),
    total_cost: Number(block.total_cost || 0),
    note: String(block.note || ''),
  }
})
const dailyRows = computed(() =>
  Array.isArray(data.value?.daily) ? ([...data.value.daily] as Array<Record<string, any>>).reverse() : [],
)

const worstPattern = computed(() => {
  const p = trend.value?.worst_roas_pattern
  return p && typeof p === 'object' ? (p as Record<string, any>) : null
})

const weekdayBreakdown = computed(() => {
  const rows = worstPattern.value?.weekday_breakdown
  return Array.isArray(rows) ? (rows as Array<Record<string, any>>) : []
})

function openSiblingListing(row: Record<string, any>) {
  const id = String(row.ml_item_id || '')
  if (!id || row.is_current) return
  emit('select-item', {
    mlItemId: id,
    connectionId: props.connectionId ?? data.value?.connection?.id ?? null,
  })
}
const title = computed(
  () => listing.value.title || data.value?.ml_item_id || props.mlItemId || 'Detalle Ads',
)

const moneyFmt = new Intl.NumberFormat('es-MX', {
  style: 'currency',
  currency: 'MXN',
  maximumFractionDigits: 0,
})
const moneyFmtExact = new Intl.NumberFormat('es-MX', {
  style: 'currency',
  currency: 'MXN',
  maximumFractionDigits: 2,
})

function shortDate(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    const d = new Date(`${iso}T12:00:00`)
    return d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })
  } catch {
    return iso
  }
}

function formatPct(v: number | null | undefined, digits = 1) {
  if (v == null || !Number.isFinite(Number(v))) return '—'
  return `${(Number(v) * 100).toFixed(digits)}%`
}

function formatRoas(v: number | null | undefined) {
  if (v == null || !Number.isFinite(Number(v))) return '—'
  return `${Number(v).toFixed(2)}x`
}

function statusClass(status: string) {
  if (status === 'red') return 'bg-rose-100 text-rose-800'
  if (status === 'yellow') return 'bg-amber-100 text-amber-800'
  return 'bg-emerald-100 text-emerald-800'
}

function roasTone(roas: number | null | undefined, target?: number | null) {
  if (roas == null || !Number.isFinite(Number(roas))) return 'text-slate-500'
  const t = target ?? Number(data.value?.target_roas || 0)
  if (t > 0 && Number(roas) >= t) return 'text-emerald-700'
  if (t > 0 && Number(roas) >= t * 0.7) return 'text-amber-700'
  return 'text-rose-700'
}

function campaignStatusClass(status: string | null | undefined) {
  const s = String(status || '').toLowerCase()
  if (s === 'active' || s === 'enabled') return 'bg-emerald-100 text-emerald-800'
  if (s === 'paused') return 'bg-amber-100 text-amber-800'
  if (s === 'deleted' || s === 'inactive') return 'bg-slate-100 text-slate-600'
  return 'bg-slate-100 text-slate-600'
}

function setChartMode(id: string) {
  if (id === 'money' || id === 'efficiency' || id === 'traffic') {
    chartMode.value = id
  }
}

const chartModeHint = computed(() => {
  if (chartMode.value === 'money') {
    return 'Barras = inversión · línea azul = revenue ads · punteada = ROAS'
  }
  if (chartMode.value === 'traffic') {
    return 'Barras = clics · línea naranja = unidades atribuidas'
  }
  return 'Línea ROAS con zonas vs meta (rojo / ámbar / verde)'
})

const chartOption = computed(() => {
  const series = seriesRows.value
  const labels = series.map((s) => shortDate(s.label || s.bucket))
  const target = Number(data.value?.target_roas || 0)
  const mode = chartMode.value

  const tooltip = {
    trigger: 'axis' as const,
    axisPointer: { type: 'cross' as const },
    backgroundColor: 'rgba(15, 23, 42, 0.92)',
    borderWidth: 0,
    padding: [10, 12],
    textStyle: { color: '#f8fafc', fontSize: 12 },
    formatter: (params: any) => {
      const list = Array.isArray(params) ? params : [params]
      const idx = list[0]?.dataIndex ?? 0
      const row = series[idx]
      if (!row) return ''
      const date = row.label || row.bucket
      return [
        `<div style="font-weight:600;margin-bottom:6px">${shortDate(date)} · ${date}</div>`,
        `Inversión: <b>${moneyFmtExact.format(Number(row.cost || 0))}</b>`,
        `Revenue ads: <b>${moneyFmtExact.format(Number(row.attributed_revenue || 0))}</b>`,
        `ROAS: <b>${formatRoas(row.roas)}</b>${
          target > 0 && row.roas != null
            ? ` (${Number(row.roas) >= target ? '✓ cumple meta' : `${(Number(row.roas) - target).toFixed(2)}x vs meta`})`
            : ''
        }`,
        `ACoS: <b>${formatPct(row.acos)}</b>`,
        `Clics: <b>${row.clicks ?? 0}</b> · CPC: <b>${row.cpc != null ? moneyFmtExact.format(Number(row.cpc)) : '—'}</b>`,
        `Unidades: <b>${Number(row.units || 0).toFixed(0)}</b>`,
      ].join('<br/>')
    },
  }

  if (mode === 'efficiency') {
    const nearFloor = target > 0 ? target * 0.7 : 0
    return {
      tooltip,
      legend: {
        data: ['ROAS diario'],
        bottom: 0,
        textStyle: { fontSize: 11, color: '#64748b' },
      },
      grid: { left: 44, right: 16, top: 36, bottom: 40 },
      xAxis: {
        type: 'category',
        data: labels,
        axisLabel: { fontSize: 10, color: '#94a3b8' },
        axisTick: { show: false },
        axisLine: { lineStyle: { color: '#e2e8f0' } },
      },
      yAxis: {
        type: 'value',
        name: 'ROAS',
        nameTextStyle: { fontSize: 10, color: '#94a3b8' },
        min: 0,
        axisLabel: {
          fontSize: 10,
          color: '#94a3b8',
          formatter: (v: number) => `${v}x`,
        },
        splitLine: { lineStyle: { color: '#f1f5f9' } },
      },
      series: [
        {
          name: 'ROAS diario',
          type: 'line',
          smooth: true,
          symbol: 'circle',
          symbolSize: 8,
          data: series.map((s) => {
            const roas = s.roas
            let color = '#94a3b8'
            if (roas != null && target > 0) {
              if (Number(roas) >= target) color = '#059669'
              else if (Number(roas) >= nearFloor) color = '#d97706'
              else color = '#e11d48'
            }
            return {
              value: roas,
              itemStyle: { color },
            }
          }),
          lineStyle: { color: '#64748b', width: 2 },
          markLine:
            target > 0
              ? {
                  symbol: 'none',
                  label: {
                    formatter: `Meta ${target.toFixed(1)}x`,
                    fontSize: 10,
                    color: '#0f766e',
                    position: 'insideEndTop',
                  },
                  lineStyle: { type: 'dashed', color: '#0f766e', width: 1.75 },
                  data: [{ yAxis: target }],
                }
              : undefined,
          markArea:
            target > 0
              ? {
                  silent: true,
                  data: [
                    [
                      {
                        yAxis: nearFloor,
                        itemStyle: { color: 'rgba(251, 191, 36, 0.12)' },
                      },
                      { yAxis: target },
                    ],
                    [
                      {
                        yAxis: target,
                        itemStyle: { color: 'rgba(16, 185, 129, 0.10)' },
                      },
                      { yAxis: 'max' },
                    ],
                    [
                      {
                        yAxis: 0,
                        itemStyle: { color: 'rgba(244, 63, 94, 0.06)' },
                      },
                      { yAxis: nearFloor },
                    ],
                  ],
                }
              : undefined,
        },
      ],
    }
  }

  if (mode === 'traffic') {
    return {
      tooltip,
      legend: {
        data: ['Clics', 'Unidades'],
        bottom: 0,
        textStyle: { fontSize: 11, color: '#64748b' },
      },
      grid: { left: 44, right: 44, top: 28, bottom: 40 },
      xAxis: {
        type: 'category',
        data: labels,
        axisLabel: { fontSize: 10, color: '#94a3b8' },
        axisTick: { show: false },
        axisLine: { lineStyle: { color: '#e2e8f0' } },
      },
      yAxis: [
        {
          type: 'value',
          name: 'Clics',
          nameTextStyle: { fontSize: 10, color: '#94a3b8' },
          axisLabel: { fontSize: 10, color: '#94a3b8' },
          splitLine: { lineStyle: { color: '#f1f5f9' } },
        },
        {
          type: 'value',
          name: 'Uds',
          nameTextStyle: { fontSize: 10, color: '#94a3b8' },
          axisLabel: { fontSize: 10, color: '#94a3b8' },
          splitLine: { show: false },
        },
      ],
      series: [
        {
          name: 'Clics',
          type: 'bar',
          barMaxWidth: 18,
          data: series.map((s) => s.clicks || 0),
          itemStyle: { color: '#0f766e', borderRadius: [3, 3, 0, 0] },
        },
        {
          name: 'Unidades',
          type: 'line',
          yAxisIndex: 1,
          smooth: true,
          symbolSize: 6,
          data: series.map((s) => Number(s.units || 0)),
          itemStyle: { color: '#ea580c' },
          lineStyle: { width: 2.5 },
        },
      ],
    }
  }

  // money: dual axis — spend/revenue left, ROAS right
  return {
    tooltip,
    legend: {
      data: ['Inversión', 'Revenue ads', 'ROAS'],
      bottom: 0,
      textStyle: { fontSize: 11, color: '#64748b' },
    },
    grid: { left: 52, right: 44, top: 28, bottom: 40 },
    xAxis: {
      type: 'category',
      data: labels,
      axisLabel: { fontSize: 10, color: '#94a3b8' },
      axisTick: { show: false },
      axisLine: { lineStyle: { color: '#e2e8f0' } },
    },
    yAxis: [
      {
        type: 'value',
        name: 'MXN',
        nameTextStyle: { fontSize: 10, color: '#94a3b8' },
        axisLabel: {
          fontSize: 10,
          color: '#94a3b8',
          formatter: (v: number) => moneyFmt.format(v).replace('MX$', '$'),
        },
        splitLine: { lineStyle: { color: '#f1f5f9' } },
      },
      {
        type: 'value',
        name: 'ROAS',
        nameTextStyle: { fontSize: 10, color: '#94a3b8' },
        axisLabel: {
          fontSize: 10,
          color: '#94a3b8',
          formatter: (v: number) => `${v}x`,
        },
        splitLine: { show: false },
      },
    ],
    series: [
      {
        name: 'Inversión',
        type: 'bar',
        barMaxWidth: 16,
        data: series.map((s) => s.cost),
        itemStyle: { color: '#0f766e', borderRadius: [3, 3, 0, 0] },
      },
      {
        name: 'Revenue ads',
        type: 'line',
        smooth: true,
        symbol: 'circle',
        symbolSize: 6,
        data: series.map((s) => s.attributed_revenue),
        itemStyle: { color: '#0369a1' },
        lineStyle: { width: 2.5 },
      },
      {
        name: 'ROAS',
        type: 'line',
        yAxisIndex: 1,
        smooth: true,
        symbol: 'none',
        data: series.map((s) => s.roas),
        itemStyle: { color: '#7c3aed' },
        lineStyle: { width: 2, type: 'dashed' },
        markLine:
          target > 0
            ? {
                symbol: 'none',
                label: {
                  formatter: `Obj ${target.toFixed(1)}x`,
                  fontSize: 10,
                  color: '#0f766e',
                  position: 'insideEndTop',
                },
                lineStyle: { type: 'dotted', color: '#0f766e', width: 1.25 },
                data: [{ yAxis: target }],
              }
            : undefined,
      },
    ],
  }
})

function onClose() {
  salesOpen.value = false
  roasOpen.value = false
  close()
  emit('close')
}

function openSales() {
  salesOpen.value = true
}

function openRoasInsight() {
  roasOpen.value = true
}

function focusRoasChart() {
  roasOpen.value = false
  chartMode.value = 'efficiency'
  requestAnimationFrame(() => {
    chartSectionRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  })
}

const roasInsight = computed(() => data.value?.roas_insight || null)
const roasZoneClass = computed(() => {
  if (roasInsight.value?.target_unreliable) return 'text-slate-700'
  const zone = roasInsight.value?.zone
  if (zone === 'above') return 'text-emerald-700'
  if (zone === 'near') return 'text-amber-700'
  if (zone === 'below') return 'text-rose-700'
  return 'text-slate-800'
})
const roasZoneLabel = computed(() => {
  const zone = roasInsight.value?.zone
  if (zone === 'above') return 'en meta'
  if (zone === 'near') return 'casi'
  if (zone === 'below') return 'bajo meta'
  return 'ver detalle'
})
const statusLabelText = computed(() => {
  return score.value?.status_label
    || (score.value?.status === 'red' ? 'Actuar' : score.value?.status === 'yellow' ? 'Cuidado' : score.value?.status === 'green' ? 'Bien' : null)
})
</script>

<template>
  <SlideOverShell
    :show="show && props.show"
    :title="title"
    :loading="loading"
    :error="error"
    :max-width="ADS_ENTITY_DETAIL_SLIDE_OVER_WIDTH_CLASS"
    accessibility-title="Detalle de publicidad"
    @close="onClose"
    @retry="retry"
  >
    <template #header-actions>
      <ConnectionChip
        v-if="data?.connection"
        class="mr-0.5 max-w-[7.5rem] sm:max-w-[14rem]"
        :connection="data.connection"
        :account-only="false"
        :compact="false"
      />
    </template>

    <div v-if="data" class="space-y-4 overflow-y-auto p-4 md:p-5">
      <!-- Header compacto -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
          <p class="font-mono text-xs text-slate-500">{{ data.ml_item_id }}</p>
          <p class="mt-0.5 truncate text-base font-semibold text-slate-900" :title="title">
            {{ title }}
          </p>
          <p class="mt-1 text-xs text-slate-500">
            {{ data.period?.start }} → {{ data.period?.end }} ({{ data.period?.days }}d)
          </p>
        </div>
        <span
          v-if="statusLabelText"
          class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
          :class="statusClass(score?.status || 'green')"
        >
          {{ statusLabelText }}
        </span>
      </div>

      <!-- Veredicto -->
      <div
        v-if="statusLabelText || score?.status_reason || roasInsight?.verdict"
        class="rounded-lg border border-slate-200 bg-white p-3"
      >
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0 flex-1">
            <p class="text-base font-semibold text-slate-900">
              Veredicto: {{ statusLabelText || '—' }}
            </p>
            <p class="mt-1 text-xs text-slate-600">
              {{ score?.status_reason || roasInsight?.verdict }}
            </p>
          </div>
          <div class="flex shrink-0 flex-wrap gap-2 lg:justify-end">
            <button
              type="button"
              class="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-medium text-white"
              @click="openRoasInsight"
            >
              Ver qué hacer
            </button>
            <button
              type="button"
              class="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-700"
              @click="openSales"
            >
              Ver ventas
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="roasInsight?.target_unreliable"
        class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950"
      >
        No usamos esta meta para asustarte: faltan datos de ganancia. La meta es provisional.
      </div>

      <p class="text-xs text-slate-500">
        {{
          data.data_sources?.note ||
          'Mercado Ads mide atribución. Nosotros la cruzamos con tu ganancia y tus órdenes.'
        }}
      </p>

      <!-- Dos columnas: KPIs | Evolución -->
      <div class="grid gap-4 lg:grid-cols-12">
        <div class="flex flex-col gap-3 lg:col-span-5">
          <button
            type="button"
            class="rounded-lg border p-3 text-left transition hover:ring-1 hover:ring-teal-200"
            :class="
              roasInsight?.target_unreliable
                ? 'border-slate-200 bg-white'
                : roasInsight?.zone === 'above'
                  ? 'border-emerald-200 bg-emerald-50/50'
                  : roasInsight?.zone === 'near'
                    ? 'border-amber-200 bg-amber-50/50'
                    : 'border-rose-200 bg-rose-50/50'
            "
            @click="openRoasInsight"
          >
            <div class="flex flex-wrap items-center gap-1.5">
              <span
                class="rounded px-1 py-0.5 text-[9px] font-semibold uppercase bg-teal-100 text-teal-800"
              >
                Nuestro cálculo
              </span>
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                ¿Cubre la ganancia?
              </p>
            </div>
            <p class="mt-1 text-lg font-semibold tabular-nums" :class="roasZoneClass">
              {{ formatRoas(roasInsight?.actual ?? kpis.roas) }}
              <span class="text-xs font-normal text-slate-500">
                / {{ formatRoas(roasInsight?.target ?? data.target_roas) }}
              </span>
            </p>
            <p class="mt-0.5 text-xs font-medium text-teal-700 underline decoration-dotted">
              {{ roasZoneLabel }} · ver ventas por $1
            </p>
          </button>

          <div
            class="rounded-lg border p-3"
            :class="
              score?.waste
                ? 'border-rose-200 bg-rose-50/60'
                : 'border-slate-200 bg-white'
            "
          >
            <div class="flex flex-wrap items-center gap-1.5">
              <span
                class="rounded px-1 py-0.5 text-[9px] font-semibold uppercase bg-teal-100 text-teal-800"
              >
                Nuestro cálculo
              </span>
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Desperdicio
              </p>
            </div>
            <p
              class="mt-1 text-base font-semibold"
              :class="score?.waste ? 'text-rose-800' : 'text-slate-900'"
            >
              <template v-if="score?.waste">Gastaste y no hubo ventas atribuidas</template>
              <template v-else>Sin desperdicio detectado</template>
            </p>
            <p class="mt-0.5 text-xs text-slate-500">Estado {{ statusLabelText || '—' }}</p>
          </div>

          <div
            class="rounded-lg border p-3"
            :class="
              data.reconciliation?.alert
                ? 'border-amber-200 bg-amber-50/70'
                : 'border-slate-200 bg-white'
            "
          >
            <div class="flex flex-wrap items-center gap-1.5">
              <span
                class="rounded px-1 py-0.5 text-[9px] font-semibold uppercase bg-teal-100 text-teal-800"
              >
                Nuestro cálculo
              </span>
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Diferencia con tu P&amp;L
              </p>
            </div>
            <p class="mt-1 text-lg font-semibold tabular-nums text-slate-900">
              <MoneyText
                :amount="data.reconciliation?.pnl_ads_delta ?? kpis.pnl_ads_delta"
                :currency="currency"
              />
            </p>
            <p class="mt-0.5 text-xs text-slate-500">
              Ads sin órdenes todavía
              <MoneyText
                :amount="data.reconciliation?.ads_residual ?? kpis.ads_residual"
                :currency="currency"
              />
            </p>
          </div>

          <div
            v-if="data.contribution"
            class="rounded-lg border border-slate-200 bg-white p-3"
          >
            <div class="flex flex-wrap items-center gap-1.5">
              <span
                class="rounded px-1 py-0.5 text-[9px] font-semibold uppercase bg-teal-100 text-teal-800"
              >
                Nuestro cálculo
              </span>
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Retorno de margen
              </p>
            </div>
            <p class="mt-1 text-lg font-semibold tabular-nums text-slate-900">
              {{ formatRoas(data.contribution.contribution_roas) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500">
              margen est.
              <MoneyText
                :amount="data.contribution.estimated_margin_amount"
                :currency="currency"
              />
            </p>
            <p class="mt-1 text-xs text-slate-600">{{ data.contribution.plain }}</p>
          </div>

          <p
            v-if="data.reconciliation?.alert"
            class="text-xs text-amber-900"
          >
            {{ data.reconciliation.note }}
            Abrí ventas acreditadas para sincronizar órdenes del periodo si la cobertura es baja.
          </p>
          <p v-if="score?.waste" class="text-xs text-rose-800">
            Desperdicio: hubo gasto sin unidades atribuidas en la ventana.
          </p>
        </div>

        <!-- Evolución: gráfico primero, patrón aparte -->
        <div class="flex flex-col gap-3 lg:col-span-7">
          <div
            ref="chartSectionRef"
            class="overflow-hidden rounded-lg border border-slate-200 bg-white"
          >
            <div
              class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-3 py-2.5"
            >
              <div>
                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Evolución diaria
                </h4>
                <p class="mt-0.5 text-xs text-slate-500">
                  {{ data.period?.days || 14 }} días · {{ chartModeHint }}
                </p>
              </div>
              <div class="inline-flex rounded-md border border-slate-200 p-0.5 text-xs">
                <button
                  v-for="opt in [
                    { id: 'money', label: 'Dinero' },
                    { id: 'efficiency', label: 'ROAS' },
                    { id: 'traffic', label: 'Tráfico' },
                  ]"
                  :key="opt.id"
                  type="button"
                  class="rounded px-2 py-1 font-medium transition"
                  :class="
                    chartMode === opt.id
                      ? 'bg-slate-900 text-white'
                      : 'text-slate-600 hover:bg-slate-50'
                  "
                  @click="setChartMode(opt.id)"
                >
                  {{ opt.label }}
                </button>
              </div>
            </div>

            <div class="px-2 pb-2 pt-1">
              <DashboardChart
                :key="`ads-chart-${chartMode}`"
                :option="chartOption"
                height="300px"
              />
            </div>

            <div
              v-if="trend.days_with_spend"
              class="grid grid-cols-2 gap-px border-t border-slate-100 bg-slate-100 sm:grid-cols-4"
            >
              <div class="bg-white px-3 py-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Mejor ROAS
                </p>
                <p class="mt-0.5 text-xs font-semibold tabular-nums text-emerald-700">
                  {{ formatRoas(trend.best_roas_day?.roas) }}
                  <span class="font-normal text-slate-500">
                    · {{ shortDate(trend.best_roas_day?.date) }}
                  </span>
                </p>
              </div>
              <div class="bg-white px-3 py-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Peor ROAS
                </p>
                <p class="mt-0.5 text-xs font-semibold tabular-nums text-rose-700">
                  {{ formatRoas(trend.worst_roas_day?.roas) }}
                  <span class="font-normal text-slate-500">
                    · {{ shortDate(trend.worst_roas_day?.date) }}
                  </span>
                </p>
              </div>
              <div class="bg-white px-3 py-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Día con más gasto
                </p>
                <p class="mt-0.5 text-xs font-semibold">
                  <MoneyText :amount="trend.peak_spend_day?.cost" :currency="currency" />
                  <span class="font-normal text-slate-500">
                    · {{ shortDate(trend.peak_spend_day?.date) }}
                  </span>
                </p>
              </div>
              <div class="bg-white px-3 py-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Días vs meta
                </p>
                <p class="mt-0.5 text-xs font-semibold tabular-nums">
                  <span class="text-emerald-700">{{ trend.days_above_target ?? 0 }}</span>
                  <span class="font-normal text-slate-500"> ok / </span>
                  <span class="text-rose-700">{{ trend.days_below_target ?? 0 }}</span>
                  <span class="font-normal text-slate-500">
                    bajo · {{ trend.zero_revenue_days ?? 0 }} sin rev.
                  </span>
                </p>
              </div>
            </div>
          </div>

          <!-- Patrón: card separada, resumen + detalle colapsable -->
          <div
            v-if="worstPattern?.plain"
            class="overflow-hidden rounded-lg border border-slate-200 bg-white"
          >
            <div class="border-l-4 border-rose-400 px-3 py-2.5">
              <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Por qué baja el ROAS
                  </p>
                  <p class="mt-1 text-sm font-semibold text-slate-900">
                    {{ worstPattern.label || 'Patrón detectado' }}
                  </p>
                  <p class="mt-1 text-xs text-slate-600 leading-snug">
                    {{ worstPattern.root_factor_plain || worstPattern.plain }}
                  </p>
                </div>
                <p class="shrink-0 text-xs text-slate-500">
                  Patrones {{ worstPattern.pattern_days || 90 }}d
                </p>
              </div>

              <div
                v-if="worstPattern.weekday_hint"
                class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600"
              >
                <span>
                  Día más flojo:
                  <span class="font-semibold text-slate-900">{{
                    worstPattern.weekday_hint
                  }}</span>
                </span>
                <span v-if="worstPattern.bad_day_count" class="text-slate-500">
                  {{ worstPattern.bad_day_count }} días malos
                </span>
              </div>

              <button
                v-if="weekdayBreakdown.length"
                type="button"
                class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-teal-800 hover:text-teal-950"
                @click="patternDetailsOpen = !patternDetailsOpen"
              >
                {{ patternDetailsOpen ? 'Ocultar' : 'Ver' }} por día de semana
                <ChevronDown
                  class="h-3.5 w-3.5 transition"
                  :class="patternDetailsOpen ? 'rotate-180' : ''"
                />
              </button>
            </div>

            <div
              v-if="patternDetailsOpen && weekdayBreakdown.length"
              class="border-t border-slate-100"
            >
              <table class="min-w-full text-xs">
                <thead class="bg-slate-50 text-left text-slate-500">
                  <tr>
                    <th class="px-3 py-2 font-medium">Día</th>
                    <th class="px-3 py-2 text-right font-medium">Días malos</th>
                    <th class="px-3 py-2 text-right font-medium">ROAS prom.</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="w in weekdayBreakdown"
                    :key="w.name"
                    class="border-t border-slate-100"
                    :class="
                      String(w.name).toLowerCase() ===
                      String(worstPattern.weekday_hint || '').toLowerCase()
                        ? 'bg-rose-50/50'
                        : ''
                    "
                  >
                    <td class="px-3 py-1.5 font-medium capitalize text-slate-800">
                      {{ w.name }}
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums text-slate-600">
                      {{ w.bad_days }}/{{ w.days }}
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums font-medium text-slate-800">
                      {{ w.roas != null ? `${Number(w.roas).toFixed(1)}x` : '—' }}
                    </td>
                  </tr>
                </tbody>
              </table>
              <p class="border-t border-slate-100 px-3 py-2 text-xs text-slate-500">
                {{
                  worstPattern.hour_note ||
                  'Mercado Ads no expone métricas por hora; solo por día.'
                }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Métricas detalladas -->
      <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <AdsPlainMetric
          :label="ADS_PDP.spend.label"
          source="ml"
          :plain="ADS_PDP.spend.plain"
          :how="ADS_PDP.spend.how"
        >
          <MoneyText :amount="kpis.cost" :currency="currency" />
        </AdsPlainMetric>
        <AdsPlainMetric
          label="ROAS (atribuido)"
          source="ml"
          :plain="ADS_PDP.roas.plain"
          :how="ADS_PDP.roas.how"
          :value="formatRoas(kpis.roas)"
        >
          {{ formatRoas(kpis.roas) }}
        </AdsPlainMetric>
        <AdsPlainMetric
          :label="ADS_PDP.tacos.label"
          source="ours"
          :plain="ADS_PDP.tacos.plain"
          :how="ADS_PDP.tacos.how"
          :value="formatPct(kpis.tacos ?? kpis.blended_acos)"
        >
          {{ formatPct(kpis.tacos ?? kpis.blended_acos) }}
        </AdsPlainMetric>
        <AdsPlainMetric
          :label="ADS_PDP.waste.label"
          source="ml"
          :plain="ADS_PDP.waste.plain"
          :why="ADS_PDP.waste.why"
          :how="ADS_PDP.waste.how"
          :class="(kpis.waste_spend || 0) > 0 ? 'ring-1 ring-rose-100 bg-rose-50/60' : ''"
        >
          <MoneyText :amount="kpis.waste_spend || 0" :currency="currency" />
        </AdsPlainMetric>
        <AdsPlainMetric
          label="Revenue ads"
          source="ml"
          plain="Ventas que ML atribuye a la publicidad (ítem + día)."
          how="total_amount (directo + indirecto) de Product Ads."
        >
          <MoneyText :amount="kpis.attributed_revenue" :currency="currency" />
        </AdsPlainMetric>
        <AdsPlainMetric
          :label="ADS_PDP.direct.label"
          source="ml"
          :plain="ADS_PDP.direct.plain"
          :how="ADS_PDP.direct.how"
        >
          <MoneyText :amount="kpis.direct_amount || 0" :currency="currency" />
        </AdsPlainMetric>
        <AdsPlainMetric
          :label="ADS_PDP.indirect.label"
          source="ml"
          :plain="ADS_PDP.indirect.plain"
          :how="ADS_PDP.indirect.how"
        >
          <MoneyText :amount="kpis.indirect_amount || 0" :currency="currency" />
        </AdsPlainMetric>
        <AdsPlainMetric
          :label="ADS_PDP.organic.label"
          source="ml"
          :plain="ADS_PDP.organic.plain"
          :why="ADS_PDP.organic.why"
          :how="ADS_PDP.organic.how"
        >
          <MoneyText :amount="kpis.organic_amount || 0" :currency="currency" />
          <span class="text-xs font-normal text-slate-500">
            · {{ Number(kpis.organic_units || 0).toFixed(0) }} uds
          </span>
        </AdsPlainMetric>
        <AdsPlainMetric
          label="GMV local"
          source="local"
          plain="Ventas de tus órdenes locales del mismo ítem en la ventana."
          how="Suma de líneas de órdenes sincronizadas con este ml_item_id."
        >
          <MoneyText :amount="kpis.local_gmv" :currency="currency" />
        </AdsPlainMetric>
        <AdsPlainMetric
          label="Ads en P&L"
          source="local"
          plain="Costo de ads ya cargado en el P&L de órdenes (solo si ML atribuyó)."
          how="Eventos expected_advertising en órdenes."
        >
          <MoneyText :amount="kpis.pnl_ads" :currency="currency" />
        </AdsPlainMetric>
        <AdsPlainMetric
          :label="ADS_PDP.residual.label"
          source="ours"
          :plain="ADS_PDP.residual.plain"
          :why="ADS_PDP.residual.why"
          :how="ADS_PDP.residual.how"
        >
          <MoneyText :amount="kpis.ads_residual || 0" :currency="currency" />
        </AdsPlainMetric>
        <AdsPlainMetric
          label="Clics · CPC"
          source="ml"
          :value="`${kpis.clicks ?? 0}`"
          plain="Clics totales y costo promedio por clic."
          how="clicks y cost/clicks de Product Ads."
        >
          {{ kpis.clicks ?? 0 }}
          <span class="text-xs font-normal text-slate-500">
            · <MoneyText :amount="kpis.cpc" :currency="currency" />
          </span>
        </AdsPlainMetric>
        <AdsPlainMetric
          label="Unidades atr."
          source="ml"
          :value="
            Number((kpis.direct_units || 0) + (kpis.indirect_units || 0)).toFixed(0)
          "
          plain="Unidades que ML atribuye a ads (directas + indirectas)."
          why="Abrí Ver ventas para cruzar con tus órdenes locales."
          how="direct_units_quantity + indirect_units_quantity."
        />
        <AdsPlainMetric
          label="Margen est."
          source="local"
          :value="score?.margin_rate != null ? formatPct(score.margin_rate) : '—'"
          plain="Margen estimado del producto o catálogo."
          how="Tomado del scorecard / costos locales."
        />
        <AdsPlainMetric
          label="CTR"
          source="ml"
          :value="formatPct(kpis.ctr, 2)"
          plain="Clics sobre impresiones."
          how="clicks / prints de Product Ads."
        />
      </div>

      <!-- Campañas / Publicaciones / Día a día -->
      <div class="overflow-hidden rounded-lg border border-slate-200">
        <div
          class="flex flex-wrap items-center gap-1 border-b border-slate-100 bg-slate-50/80 px-3 py-1.5"
        >          <button
            type="button"
            class="rounded-md px-2.5 py-1 text-xs font-medium transition"
            :class="
              detailTab === 'campaigns'
                ? 'bg-white text-slate-900 shadow-sm'
                : 'text-slate-500 hover:text-slate-800'
            "
            @click="detailTab = 'campaigns'"
          >
            Por campaña
            <span class="ml-1 tabular-nums text-slate-400">{{ campaignRows.length }}</span>
          </button>
          <button
            type="button"
            class="rounded-md px-2.5 py-1 text-xs font-medium transition"
            :class="
              detailTab === 'listings'
                ? 'bg-white text-slate-900 shadow-sm'
                : 'text-slate-500 hover:text-slate-800'
            "
            @click="detailTab = 'listings'"
          >
            Publicaciones
            <span class="ml-1 tabular-nums text-slate-400">{{
              campaignListings.listing_count
            }}</span>
          </button>
          <button
            type="button"
            class="rounded-md px-2.5 py-1 text-xs font-medium transition"
            :class="
              detailTab === 'daily'
                ? 'bg-white text-slate-900 shadow-sm'
                : 'text-slate-500 hover:text-slate-800'
            "
            @click="detailTab = 'daily'"
          >
            Día a día
            <span class="ml-1 tabular-nums text-slate-400">{{ dailyRows.length }}</span>
          </button>
        </div>

        <div v-if="detailTab === 'campaigns'">
          <div
            v-if="!campaignRows.length"
            class="px-4 py-8 text-center text-sm text-slate-500"
          >
            Sin desglose por campaña en esta ventana.
          </div>
          <div v-else class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                  <th class="px-2.5 py-2 font-medium">Campaña</th>
                  <th class="px-2.5 py-2 font-medium">Share</th>
                  <th class="px-2.5 py-2 text-right font-medium">Gasto</th>
                  <th class="px-2.5 py-2 text-right font-medium">Revenue</th>
                  <th class="px-2.5 py-2 text-right font-medium">ROAS</th>
                  <th class="px-2.5 py-2 text-right font-medium">ACoS</th>
                  <th class="px-2.5 py-2 text-right font-medium">Clics</th>
                  <th class="px-2.5 py-2 text-right font-medium">CPC</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in campaignRows"
                  :key="row.key || row.label"
                  class="border-t border-slate-100 align-top"
                >
                  <td class="max-w-[16rem] px-3 py-2">
                    <p class="truncate font-medium text-slate-800" :title="row.label">
                      {{ row.label }}
                    </p>
                    <div class="mt-1 flex flex-wrap items-center gap-1">
                      <span
                        v-if="row.status"
                        class="rounded-full px-1.5 py-0.5 text-[10px] font-medium capitalize"
                        :class="campaignStatusClass(row.status)"
                      >
                        {{ row.status }}
                      </span>
                      <span v-if="row.strategy" class="text-[10px] text-slate-400">
                        {{ row.strategy }}
                      </span>
                      <span v-if="row.roas_target" class="text-[10px] text-slate-400">
                        obj {{ formatRoas(row.roas_target) }}
                      </span>
                    </div>
                  </td>
                  <td class="px-2.5 py-2">
                    <div class="flex items-center gap-1.5">
                      <div class="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100">
                        <div
                          class="h-full rounded-full bg-teal-600"
                          :style="{
                            width: `${Math.min(100, Math.round(Number(row.share_of_spend || 0) * 100))}%`,
                          }"
                        />
                      </div>
                      <span class="tabular-nums text-slate-500">
                        {{ formatPct(row.share_of_spend, 0) }}
                      </span>
                    </div>
                  </td>
                  <td class="px-2.5 py-2 text-right">
                    <MoneyText :amount="row.cost" :currency="currency" />
                  </td>
                  <td class="px-2.5 py-2 text-right">
                    <MoneyText :amount="row.attributed_revenue" :currency="currency" />
                  </td>
                  <td
                    class="px-2.5 py-2 text-right tabular-nums font-medium"
                    :class="roasTone(row.roas, row.roas_target || data.target_roas)"
                  >
                    {{ formatRoas(row.roas) }}
                  </td>
                  <td class="px-2.5 py-2 text-right tabular-nums text-slate-600">
                    {{ formatPct(row.acos) }}
                  </td>
                  <td class="px-2.5 py-2 text-right tabular-nums">{{ row.clicks ?? 0 }}</td>
                  <td class="px-2.5 py-2 text-right">
                    <MoneyText :amount="row.cpc" :currency="currency" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-else-if="detailTab === 'listings'">
          <div class="space-y-2 border-b border-slate-100 px-3 py-2.5 text-xs text-slate-600">
            <p v-if="campaignListings.other_count > 0">
              Esta publicación comparte
              {{
                campaignListings.campaigns.length === 1
                  ? 'la campaña'
                  : `${campaignListings.campaigns.length} campañas`
              }}
              <template v-if="campaignListings.campaigns.length">
                ({{
                  campaignListings.campaigns
                    .map((c) => c.name)
                    .filter(Boolean)
                    .join(', ')
                }})
              </template>
              con
              {{ campaignListings.other_count }}
              {{
                campaignListings.other_count === 1
                  ? 'otra publicación'
                  : 'otras publicaciones'
              }}
              que también gastaron en la ventana. Reparto del gasto total de esas campañas:
              <span class="font-medium text-slate-800">
                <MoneyText :amount="campaignListings.total_cost" :currency="currency" />
              </span>.
            </p>
            <p v-else-if="campaignListings.listing_count === 1">
              En estas campañas, en esta ventana, solo esta publicación tiene gasto de ads.
            </p>
            <p v-else>Sin otras publicaciones con gasto en las mismas campañas.</p>
            <p v-if="campaignListings.current_share != null" class="text-slate-500">
              Esta publicación se lleva
              {{ formatPct(campaignListings.current_share, 0) }}
              del gasto de esas campañas.
            </p>
          </div>
          <div
            v-if="!campaignListings.listings.length"
            class="px-4 py-8 text-center text-sm text-slate-500"
          >
            Sin publicaciones con gasto compartido en esta ventana.
          </div>
          <div v-else class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                  <th class="px-2.5 py-2 font-medium">Publicación</th>
                  <th class="px-2.5 py-2 font-medium">Share</th>
                  <th class="px-2.5 py-2 text-right font-medium">Gasto</th>
                  <th class="px-2.5 py-2 text-right font-medium">Revenue</th>
                  <th class="px-2.5 py-2 text-right font-medium">ROAS</th>
                  <th class="px-2.5 py-2 text-right font-medium">ACoS</th>
                  <th class="px-2.5 py-2 text-right font-medium">Clics</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in campaignListings.listings"
                  :key="row.ml_item_id"
                  class="border-t border-slate-100 align-top"
                  :class="row.is_current ? 'bg-teal-50/40' : 'hover:bg-slate-50/80'"
                >
                  <td class="max-w-[18rem] px-3 py-2">
                    <button
                      v-if="!row.is_current"
                      type="button"
                      class="block w-full text-left"
                      @click="openSiblingListing(row)"
                    >
                      <p
                        class="truncate font-medium text-teal-800 underline-offset-2 hover:underline"
                        :title="row.title"
                      >
                        {{ row.title }}
                      </p>
                      <p class="mt-0.5 font-mono text-[10px] text-slate-400">
                        {{ row.ml_item_id }}
                      </p>
                    </button>
                    <div v-else>
                      <p class="truncate font-medium text-slate-900" :title="row.title">
                        {{ row.title }}
                        <span
                          class="ml-1 rounded-full bg-teal-100 px-1.5 py-0.5 text-[10px] font-medium text-teal-800"
                        >
                          Esta
                        </span>
                      </p>
                      <p class="mt-0.5 font-mono text-[10px] text-slate-400">
                        {{ row.ml_item_id }}
                      </p>
                    </div>
                    <a
                      v-if="row.permalink"
                      :href="row.permalink"
                      target="_blank"
                      rel="noopener"
                      class="mt-1 inline-flex items-center gap-0.5 text-[10px] text-slate-500 hover:text-slate-800"
                      @click.stop
                    >
                      Ver en ML <ExternalLink class="h-3 w-3" />
                    </a>
                  </td>
                  <td class="px-2.5 py-2">
                    <div class="flex items-center gap-1.5">
                      <div class="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100">
                        <div
                          class="h-full rounded-full bg-teal-600"
                          :style="{
                            width: `${Math.min(100, Math.round(Number(row.share_of_spend || 0) * 100))}%`,
                          }"
                        />
                      </div>
                      <span class="tabular-nums text-slate-500">
                        {{ formatPct(row.share_of_spend, 0) }}
                      </span>
                    </div>
                  </td>
                  <td class="px-2.5 py-2 text-right">
                    <MoneyText :amount="row.cost" :currency="currency" />
                  </td>
                  <td class="px-2.5 py-2 text-right">
                    <MoneyText :amount="row.attributed_revenue" :currency="currency" />
                  </td>
                  <td
                    class="px-2.5 py-2 text-right tabular-nums font-medium"
                    :class="roasTone(row.roas, data.target_roas)"
                  >
                    {{ formatRoas(row.roas) }}
                  </td>
                  <td class="px-2.5 py-2 text-right tabular-nums text-slate-600">
                    {{ formatPct(row.acos) }}
                  </td>
                  <td class="px-2.5 py-2 text-right tabular-nums">{{ row.clicks ?? 0 }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p
            v-if="campaignListings.note"
            class="border-t border-slate-100 px-3 py-2 text-[11px] text-slate-500"
          >
            {{ campaignListings.note }}
          </p>
        </div>

        <div v-else>
          <div
            v-if="!dailyRows.length"
            class="px-4 py-8 text-center text-sm text-slate-500"
          >
            Sin filas diarias.
          </div>
          <div v-else class="max-h-72 overflow-auto">
            <table class="min-w-full text-xs">
              <thead class="sticky top-0 bg-slate-50 text-left text-slate-500">
                <tr>
                  <th class="px-2.5 py-2 font-medium">Fecha</th>
                  <th class="px-2.5 py-2 text-right font-medium">Gasto</th>
                  <th class="px-2.5 py-2 text-right font-medium">Revenue</th>
                  <th class="px-2.5 py-2 text-right font-medium">ROAS</th>
                  <th class="px-2.5 py-2 text-right font-medium">ACoS</th>
                  <th class="px-2.5 py-2 text-right font-medium">Uds</th>
                  <th class="px-2.5 py-2 text-right font-medium">Clics</th>
                  <th class="px-2.5 py-2 text-right font-medium">CPC</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="d in dailyRows"
                  :key="d.date"
                  class="border-t border-slate-100"
                  :class="
                    Number(d.cost || 0) > 0 && Number(d.revenue || 0) <= 0
                      ? 'bg-amber-50/60'
                      : ''
                  "
                >
                  <td class="px-2.5 py-1.5">
                    <span class="font-medium text-slate-800">{{ shortDate(d.date) }}</span>
                    <span class="ml-1 text-[10px] text-slate-400">{{ d.date }}</span>
                  </td>
                  <td class="px-2.5 py-1.5 text-right">
                    <MoneyText :amount="d.cost" :currency="currency" />
                  </td>
                  <td class="px-2.5 py-1.5 text-right">
                    <MoneyText :amount="d.revenue" :currency="currency" />
                  </td>
                  <td
                    class="px-2.5 py-1.5 text-right tabular-nums font-medium"
                    :class="roasTone(d.roas, data.target_roas)"
                  >
                    {{ formatRoas(d.roas) }}
                  </td>
                  <td class="px-2.5 py-1.5 text-right tabular-nums text-slate-600">
                    {{ formatPct(d.acos) }}
                  </td>
                  <td class="px-2.5 py-1.5 text-right tabular-nums">{{ d.units }}</td>
                  <td class="px-2.5 py-1.5 text-right tabular-nums">{{ d.clicks }}</td>
                  <td class="px-2.5 py-1.5 text-right">
                    <MoneyText :amount="d.cpc" :currency="currency" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div v-if="data.proposals?.length" class="space-y-1.5">
        <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
          Acciones sugeridas
        </h4>
        <div
          v-for="p in data.proposals"
          :key="p.id"
          class="flex items-start justify-between gap-2 rounded-md border border-amber-200 bg-amber-50/50 px-3 py-2 text-xs"
        >
          <div>
            <p class="font-medium text-amber-950">{{ p.title }}</p>
            <p class="mt-0.5 text-amber-800/80">{{ p.reason }}</p>
          </div>
          <button
            type="button"
            class="shrink-0 rounded-md bg-teal-700 px-2 py-1 text-[11px] font-medium text-white"
            @click="emit('approve-proposal', p.id)"
          >
            Aplicar
          </button>
        </div>
      </div>
    </div>

    <template #footer>
      <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
        <template #end>
          <ActionGroup>
            <a
              v-if="listing.permalink"
              :href="listing.permalink"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
            >
              Ver en ML <ExternalLink class="h-3.5 w-3.5" />
            </a>
            <ActionButton
              v-if="data?.links?.dashboard"
              :href="data.links.dashboard"
              :inertia="true"
              variant="primary"
            >
              Abrir en dashboard
            </ActionButton>
            <ActionButton variant="secondary" @click="openSales">
              Ventas acreditadas
            </ActionButton>
          </ActionGroup>
        </template>
      </ActionBar>
    </template>
  </SlideOverShell>

  <AdsAttributedSalesSlideOver
    :show="salesOpen"
    :ml-item-id="props.mlItemId"
    :connection-id="props.connectionId"
    :days="props.days"
    @close="salesOpen = false"
  />

  <AdsRoasInsightSlideOver
    :show="roasOpen"
    :insight="roasInsight"
    :currency="currency"
    :item-label="data?.ml_item_id || props.mlItemId"
    @close="roasOpen = false"
    @focus-chart="focusRoasChart"
  />
</template>
