<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import Card from '@/Components/ui/Card.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import OrderStatusPill from '@/Components/Domain/OrderStatusPill.vue'
import OrderDetailSlideOver from '@/Components/Orders/OrderDetailSlideOver.vue'
import AssortmentForecastSlideOver from '@/Components/Products/AssortmentForecastSlideOver.vue'
import DashboardChart from '@/Components/Dashboard/DashboardChart.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import {
  assortmentHealth,
  formatStockDepletionHint,
  formatStockDepletionLabel,
  stockDepletionSeverity,
} from '@/lib/stockForecast'
import { formatDateTime, formatRelativeShort } from '@/lib/utils'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  MarkPointComponent,
} from 'echarts/components'

use([
  CanvasRenderer,
  LineChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  MarkPointComponent,
])

const PERIODS = [
  { value: 'last_7_days', label: '7d' },
  { value: 'last_30_days', label: '30d' },
  { value: 'last_90_days', label: '90d' },
  { value: 'all', label: 'Todo' },
] as const

const props = withDefaults(
  defineProps<{
    productId: number | null
    period?: string
    connectionIds?: number[]
    active?: boolean
  }>(),
  {
    period: 'all',
    connectionIds: () => [],
    active: true,
  },
)

const loading = ref(false)
const error = ref<string | null>(null)
const payload = ref<Record<string, any> | null>(null)
const selectedPeriod = ref('all')
const orderSlideOpen = ref(false)
const selectedOrderId = ref<number | null>(null)
const assortmentSlideOpen = ref(false)
const chartMode = ref<'global' | 'variant'>('global')
const variantFocus = ref<'top' | 'all' | 'stockout' | 'at_risk'>('top')

const brand = '#0f766e'
const VARIANT_COLORS = [
  '#0f766e',
  '#0284c7',
  '#7c3aed',
  '#db2777',
  '#ea580c',
  '#65a30d',
  '#0891b2',
  '#4f46e5',
  '#0d9488',
  '#2563eb',
  '#c026d3',
  '#dc2626',
]

const summary = computed(() => payload.value?.summary || {})
const stock = computed(() => payload.value?.stock || {})
const forecast = computed(() => stock.value?.forecast || null)
const orders = computed(() => (Array.isArray(payload.value?.orders) ? payload.value.orders : []))
const currency = computed(() => summary.value.currency || 'MXN')

const depletionLabel = computed(() => formatStockDepletionLabel(forecast.value))
const assortment = computed(() => stock.value?.assortment || null)
const depletionHint = computed(() => formatStockDepletionHint(forecast.value, assortment.value))
const depletionTone = computed(() => stockDepletionSeverity(forecast.value))
const stockVariants = computed(() =>
  Array.isArray(stock.value?.variants) ? stock.value.variants : [],
)
const showVariantBreakdown = computed(() => stockVariants.value.length > 1)
const health = computed(() => assortmentHealth(assortment.value, forecast.value))
const stockImpact = computed(() => payload.value?.stock_impact || null)
const seriesByVariant = computed(() =>
  Array.isArray(payload.value?.series_by_variant) ? payload.value.series_by_variant : [],
)

const stockImpactLine = computed(() => {
  const impact = stockImpact.value
  if (!impact) return null
  const total =
    Number(impact.stockout_units || 0) +
    Number(impact.at_risk_units || 0) +
    Number(impact.ok_units || 0) +
    Number(impact.unknown_units || 0)
  if (total <= 0) return null
  return `Tallas agotadas aportaron ${Number(impact.stockout_pct || 0)}% de las u. del periodo · En riesgo ${Number(impact.at_risk_pct || 0)}% · Ok ${Number(impact.ok_pct || 0)}%`
})

function variantStockStatus(variantId: number): 'stockout' | 'at_risk' | 'ok' | 'unknown' {
  const row = stockVariants.value.find((v: any) => Number(v.id) === Number(variantId))
  const days = row?.forecast?.days_of_cover
  if (days === 0 || days === 0.0) return 'stockout'
  if (days == null) return 'unknown'
  if (Number(days) < 14) return 'at_risk'
  return 'ok'
}

function shortSku(sku: string | null | undefined, variantId: number): string {
  if (!sku) return `#${variantId}`
  if (sku.length <= 18) return sku
  return `${sku.slice(0, 16)}…`
}

/** Etiqueta legible: nombre de talla, o cola del SKU, o SKU corto. */
function variantLabel(variantId: number, sku?: string | null): string {
  const row = stockVariants.value.find((v: any) => Number(v.id) === Number(variantId))
  const name = typeof row?.name === 'string' ? row.name.trim() : ''
  if (name && name.length <= 28) return name

  const raw = (typeof sku === 'string' && sku) || (typeof row?.sku === 'string' ? row.sku : '')
  if (raw) {
    const parts = raw.split(/[-_/]/).filter(Boolean)
    const last = parts[parts.length - 1]
    if (last && last.length <= 10 && parts.length > 1) return last
    return shortSku(raw, variantId)
  }

  return `#${variantId}`
}

function formatCutDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  if (!m) return iso
  return `${Number(m[3])}/${Number(m[2])}`
}

/**
 * Sin snapshots históricos: si hoy está agotada, cortamos tras el último día con ventas
 * (null = hueco, la línea no baja a 0).
 */
function cutSeriesAtLastSale(
  points: Array<{ date: string; units: number }>,
  isStockout: boolean,
): { data: Array<number | null>; cutDate: string | null; cutIndex: number; cutUnits: number } {
  if (!isStockout || !points.length) {
    return {
      data: points.map((p) => Number(p.units || 0)),
      cutDate: null,
      cutIndex: -1,
      cutUnits: 0,
    }
  }

  let cutIndex = -1
  for (let i = 0; i < points.length; i++) {
    if (Number(points[i].units || 0) > 0) cutIndex = i
  }

  if (cutIndex < 0) {
    return {
      data: points.map(() => null),
      cutDate: null,
      cutIndex: -1,
      cutUnits: 0,
    }
  }

  return {
    data: points.map((p, i) => (i <= cutIndex ? Number(p.units || 0) : null)),
    cutDate: points[cutIndex].date,
    cutIndex,
    cutUnits: Number(points[cutIndex].units || 0),
  }
}

const focusedVariantSeries = computed(() => {
  const all = seriesByVariant.value as Array<{
    variant_id: number
    sku: string | null
    units_total: number
    points: Array<{ date: string; units: number }>
  }>
  if (!all.length) return []

  let filtered = all
  if (variantFocus.value === 'stockout') {
    filtered = all.filter((s) => variantStockStatus(s.variant_id) === 'stockout')
  } else if (variantFocus.value === 'at_risk') {
    filtered = all.filter((s) => variantStockStatus(s.variant_id) === 'at_risk')
  }

  if (variantFocus.value === 'top') {
    return filtered.slice(0, 8)
  }

  return filtered
})

const variantLegendItems = computed(() => {
  if (chartMode.value !== 'variant') return []
  const focused = focusedVariantSeries.value
  const baseCounts = new Map<string, number>()
  for (const s of focused) {
    const base = variantLabel(s.variant_id, s.sku)
    baseCounts.set(base, (baseCounts.get(base) || 0) + 1)
  }

  return focused.map((s, idx) => {
    const status = variantStockStatus(s.variant_id)
    const built = cutSeriesAtLastSale(s.points || [], status === 'stockout')
    const base = variantLabel(s.variant_id, s.sku)
    const label =
      (baseCounts.get(base) || 0) > 1
        ? `${base} · ${shortSku(s.sku, s.variant_id)}`
        : base
    return {
      variant_id: s.variant_id,
      label,
      sku: s.sku,
      color: VARIANT_COLORS[idx % VARIANT_COLORS.length],
      status,
      cutDate: built.cutDate,
      units_total: s.units_total,
    }
  })
})

const stockoutCutSummary = computed(() => {
  return variantLegendItems.value
    .filter((i) => i.status === 'stockout' && i.cutDate)
    .sort((a, b) => String(a.cutDate).localeCompare(String(b.cutDate)))
})

const labelByVariantId = computed(() => {
  const map = new Map<number, string>()
  for (const item of variantLegendItems.value) {
    map.set(item.variant_id, item.label)
  }
  return map
})

watch(showVariantBreakdown, (multi) => {
  if (!multi && chartMode.value === 'variant') {
    chartMode.value = 'global'
  }
})

watch(
  () => props.productId,
  () => {
    chartMode.value = 'global'
    variantFocus.value = 'top'
  },
)

function openAssortmentSlide() {
  if (!showVariantBreakdown.value) return
  assortmentSlideOpen.value = true
}

function buildUrl(productId: number) {
  const params: Record<string, unknown> = {
    product: productId,
    period: selectedPeriod.value || 'all',
  }
  if (props.connectionIds?.length) {
    params.connection_ids = props.connectionIds
  }
  return route('products.sales', params)
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
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar ventas.'
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

function selectPeriod(value: string) {
  if (selectedPeriod.value === value) return
  selectedPeriod.value = value
}

function openOrder(orderId: number) {
  selectedOrderId.value = orderId
  orderSlideOpen.value = true
}

const seriesChart = computed(() => {
  if (chartMode.value === 'variant') {
    const focused = focusedVariantSeries.value
    const dates =
      focused[0]?.points?.map((p) => p.date) ||
      (payload.value?.series || []).map((s: any) => s.date)
    const showEndLabels = focused.length <= 10 || variantFocus.value === 'stockout'

    return {
      color: VARIANT_COLORS,
      tooltip: {
        trigger: 'axis',
        backgroundColor: 'rgba(255,255,255,0.96)',
        borderColor: '#e2e8f0',
        textStyle: { fontSize: 11, color: '#0f172a' },
        formatter: (params: any) => {
          const rows = (Array.isArray(params) ? params : [params]).filter(
            (p: any) => p.value != null && p.value !== '-',
          )
          if (!rows.length) return ''
          const head = rows[0]?.axisValueLabel || rows[0]?.name || ''
          const body = rows
            .map((p: any) => {
              const series = focused.find(
                (s) =>
                  (labelByVariantId.value.get(s.variant_id) ||
                    variantLabel(s.variant_id, s.sku)) === p.seriesName,
              )
              if (!series) return `${p.marker}${p.seriesName}: ${p.value} u`
              const status = variantStockStatus(series.variant_id)
              const built = cutSeriesAtLastSale(series.points || [], status === 'stockout')
              const isCutDay = status === 'stockout' && built.cutDate === (p.axisValue || head)
              const note =
                status === 'stockout'
                  ? isCutDay
                    ? ' · fin de stock (última venta)'
                    : ' · hoy agotada'
                  : status === 'at_risk'
                    ? ' · en riesgo'
                    : ''
              return `${p.marker}<b>${p.seriesName}</b>: ${p.value} u${note}`
            })
            .join('<br/>')
          return `<div style="margin-bottom:4px;font-weight:600">${head}</div>${body}`
        },
      },
      legend: { show: false },
      grid: { left: 8, right: 12, top: 12, bottom: 8, containLabel: true },
      xAxis: {
        type: 'category',
        data: dates,
        axisLabel: { color: '#94a3b8', fontSize: 9 },
        axisLine: { lineStyle: { color: '#e2e8f0' } },
        axisTick: { show: false },
      },
      yAxis: {
        type: 'value',
        splitLine: { lineStyle: { color: '#f1f5f9' } },
        axisLabel: { color: '#94a3b8', fontSize: 9 },
      },
      series: focused.map((s, idx) => {
        const status = variantStockStatus(s.variant_id)
        const isStockout = status === 'stockout'
        const built = cutSeriesAtLastSale(s.points || [], isStockout)
        const color = VARIANT_COLORS[idx % VARIANT_COLORS.length]
        const label =
          labelByVariantId.value.get(s.variant_id) || variantLabel(s.variant_id, s.sku)

        return {
          name: label,
          type: 'line',
          smooth: true,
          connectNulls: false,
          showSymbol: isStockout && built.cutIndex >= 0,
          symbol: 'circle',
          symbolSize: (_: unknown, params: { dataIndex: number }) =>
            params.dataIndex === built.cutIndex ? 9 : 0,
          itemStyle: { color },
          lineStyle: {
            color,
            width: isStockout ? 2.5 : 1.75,
            type: isStockout ? 'dashed' : status === 'at_risk' ? 'dotted' : 'solid',
          },
          data: built.data,
          ...(isStockout && built.cutDate && built.cutIndex >= 0
            ? {
                markPoint: {
                  symbol: 'circle',
                  symbolSize: 10,
                  itemStyle: {
                    color: '#fff',
                    borderColor: color,
                    borderWidth: 2.5,
                  },
                  label: showEndLabels
                    ? {
                        show: true,
                        formatter: `${label}\nagotó ${formatCutDate(built.cutDate)}`,
                        position: 'top',
                        fontSize: 9,
                        lineHeight: 12,
                        color: '#9f1239',
                        fontWeight: 600,
                        backgroundColor: 'rgba(255,255,255,0.92)',
                        padding: [2, 4],
                        borderRadius: 3,
                      }
                    : { show: false },
                  data: [
                    {
                      name: label,
                      coord: [built.cutDate, built.cutUnits],
                    },
                  ],
                },
              }
            : {}),
        }
      }),
    }
  }

  const series = payload.value?.series || []
  return {
    color: [brand, '#0284c7'],
    tooltip: {
      trigger: 'axis',
      backgroundColor: 'rgba(255,255,255,0.96)',
      borderColor: '#e2e8f0',
      textStyle: { fontSize: 11, color: '#0f172a' },
    },
    legend: {
      top: 0,
      right: 0,
      textStyle: { fontSize: 10, color: '#64748b' },
      itemWidth: 10,
      itemHeight: 8,
    },
    grid: { left: 8, right: 8, top: 28, bottom: 8, containLabel: true },
    xAxis: {
      type: 'category',
      data: series.map((s: any) => s.date),
      axisLabel: { color: '#94a3b8', fontSize: 9 },
      axisLine: { lineStyle: { color: '#e2e8f0' } },
      axisTick: { show: false },
    },
    yAxis: [
      {
        type: 'value',
        splitLine: { lineStyle: { color: '#f1f5f9' } },
        axisLabel: { color: '#94a3b8', fontSize: 9 },
      },
      {
        type: 'value',
        splitLine: { show: false },
        axisLabel: { color: '#94a3b8', fontSize: 9 },
      },
    ],
    series: [
      {
        name: 'Unidades',
        type: 'line',
        smooth: true,
        showSymbol: false,
        data: series.map((s: any) => s.units || 0),
      },
      {
        name: 'Ingresos',
        type: 'line',
        yAxisIndex: 1,
        smooth: true,
        showSymbol: false,
        data: series.map((s: any) => +(s.sales || 0).toFixed(2)),
      },
    ],
  }
})

const hasChartData = computed(() => {
  if (chartMode.value === 'variant') {
    return focusedVariantSeries.value.length > 0
  }
  return (payload.value?.series || []).length > 0
})

/** Más tallas → más alto el chart para leer curvas y leyenda. */
const seriesChartHeight = computed(() => {
  if (chartMode.value !== 'variant') return '200px'
  const n = focusedVariantSeries.value.length
  if (n <= 4) return '200px'
  if (n <= 8) return '260px'
  if (n <= 16) return '320px'
  if (n <= 32) return '400px'
  return '480px'
})
</script>

<template>
  <div class="space-y-3 p-3 sm:p-4">
    <div
      v-if="loading"
      class="py-16 text-center text-sm text-muted-foreground"
    >
      Cargando ventas…
    </div>
    <div
      v-else-if="!productId"
      class="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-muted-foreground"
    >
      Este ítem aún no está matchado a un producto del sistema.
    </div>
    <div
      v-else-if="error"
      class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800"
    >
      {{ error }}
      <button
        type="button"
        class="ml-2 font-semibold underline"
        @click="load"
      >
        Reintentar
      </button>
    </div>
    <template v-else-if="payload">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-1">
          <button
            v-for="p in PERIODS"
            :key="p.value"
            type="button"
            class="inline-flex h-7 items-center rounded-full border px-2.5 text-[11px] font-medium transition-colors"
            :class="
              selectedPeriod === p.value
                ? 'border-slate-900 bg-slate-900 text-white'
                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
            "
            @click="selectPeriod(p.value)"
          >
            {{ p.label }}
          </button>
        </div>
        <span class="text-[10px] text-muted-foreground">
          {{ payload.period?.label || selectedPeriod }}
        </span>
      </div>

      <div class="grid grid-cols-2 gap-2 lg:grid-cols-3">
        <Card
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Órdenes
          </p>
          <p class="mt-1 text-xl font-semibold tracking-tight tabular-nums">
            {{ summary.orders_count ?? 0 }}
          </p>
        </Card>
        <Card
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Unidades
          </p>
          <p class="mt-1 text-xl font-semibold tracking-tight tabular-nums">
            {{ summary.units_sold ?? 0 }}
          </p>
        </Card>
        <Card
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Ingresos
          </p>
          <p class="mt-1 text-xl font-semibold tracking-tight tabular-nums">
            <MoneyText
              :amount="summary.gross_sales"
              :currency="currency"
            />
          </p>
        </Card>
        <Card
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Utilidad
          </p>
          <p class="mt-1 text-xl font-semibold tracking-tight tabular-nums">
            <MoneyText
              :amount="summary.attributed_profit"
              :currency="currency"
            />
          </p>
          <p class="mt-0.5 text-[10px] text-muted-foreground">
            Prorrateada en pedidos multi-producto
          </p>
          <p
            v-if="summary.incomplete_cogs || summary.incomplete_profit"
            class="mt-0.5 text-[10px] text-amber-700"
          >
            Algunos pedidos sin costo o P&L completo
          </p>
        </Card>
        <Card
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Stock canal
          </p>
          <p class="mt-1 text-xl font-semibold tracking-tight tabular-nums">
            {{ stock.channel_stock ?? 0 }}
          </p>
        </Card>
        <Card
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Stock interno
          </p>
          <p class="mt-1 text-xl font-semibold tracking-tight tabular-nums">
            {{ stock.internal_available ?? stock.internal_stock ?? 0 }}
          </p>
          <p class="mt-0.5 text-[10px] text-muted-foreground">
            Disponible · on-hand {{ stock.internal_stock ?? 0 }}
          </p>
        </Card>
        <Card
          v-if="!showVariantBreakdown"
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <div class="flex items-center justify-between gap-1">
            <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
              Se acaba en
            </p>
            <Badge
              v-if="stock.low_stock"
              variant="warning"
              class="h-4 px-1 text-[9px]"
            >
              Bajo
            </Badge>
          </div>
          <p
            class="mt-1 text-xl font-semibold tracking-tight tabular-nums"
            :class="{
              'text-rose-700': depletionTone === 'critical',
              'text-amber-700': depletionTone === 'warning',
            }"
            :title="depletionHint"
          >
            {{ depletionLabel }}
          </p>
          <p class="mt-0.5 text-[10px] text-muted-foreground">
            {{ depletionHint || 'Según ventas recientes' }}
          </p>
        </Card>
      </div>

      <button
        v-if="showVariantBreakdown"
        type="button"
        class="flex w-full items-center justify-between gap-2 rounded-xl border border-slate-200/80 bg-white px-3 py-2.5 text-left transition hover:border-slate-300 hover:bg-slate-50/80"
        @click="openAssortmentSlide"
      >
        <div class="min-w-0">
          <p class="text-[12px] font-semibold text-slate-900">
            Surtido: {{ health.title }}
          </p>
          <p class="mt-0.5 truncate text-[10px] text-muted-foreground">
            {{ stockVariants.length }} tallas ·
            <template v-if="(assortment?.stockout_count ?? 0) > 0">
              {{ assortment.stockout_count }} agotadas ·
            </template>
            <template v-if="(assortment?.at_risk_count ?? 0) > 0">
              {{ assortment.at_risk_count }} en riesgo ·
            </template>
            {{ depletionLabel }} · abrir detalle
          </p>
        </div>
        <span
          class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
          :class="{
            'bg-rose-100 text-rose-900': health.level === 'danger',
            'bg-amber-100 text-amber-900': health.level === 'attention',
            'bg-emerald-100 text-emerald-900': health.level === 'healthy',
            'bg-slate-100 text-slate-700': health.level === 'unknown',
          }"
        >
          {{ health.title }}
        </span>
      </button>

      <Card
        class="rounded-xl border-slate-200/70"
        content-class="p-3"
      >
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-[13px] font-semibold tracking-tight">
            Evolución
          </h3>
          <div
            v-if="showVariantBreakdown"
            class="inline-flex rounded-full border border-slate-200 bg-slate-50 p-0.5"
          >
            <button
              type="button"
              class="rounded-full px-2.5 py-1 text-[10px] font-medium transition-colors"
              :class="
                chartMode === 'global'
                  ? 'bg-white text-slate-900 shadow-sm'
                  : 'text-slate-500 hover:text-slate-700'
              "
              @click="chartMode = 'global'"
            >
              Global
            </button>
            <button
              type="button"
              class="rounded-full px-2.5 py-1 text-[10px] font-medium transition-colors"
              :class="
                chartMode === 'variant'
                  ? 'bg-white text-slate-900 shadow-sm'
                  : 'text-slate-500 hover:text-slate-700'
              "
              @click="chartMode = 'variant'"
            >
              Por talla
            </button>
          </div>
        </div>

        <div
          v-if="chartMode === 'variant' && showVariantBreakdown"
          class="mb-2 flex flex-wrap items-center gap-1"
        >
          <button
            v-for="chip in [
              { value: 'top', label: 'Top ventas' },
              { value: 'all', label: 'Todas' },
              { value: 'stockout', label: 'Agotadas' },
              { value: 'at_risk', label: 'En riesgo' },
            ]"
            :key="chip.value"
            type="button"
            class="inline-flex h-6 items-center rounded-full border px-2 text-[10px] font-medium transition-colors"
            :class="
              variantFocus === chip.value
                ? 'border-slate-900 bg-slate-900 text-white'
                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
            "
            @click="variantFocus = chip.value as 'top' | 'all' | 'stockout' | 'at_risk'"
          >
            {{ chip.label }}
          </button>
        </div>

        <DashboardChart
          v-if="hasChartData"
          :option="seriesChart"
          :height="seriesChartHeight"
        />
        <p
          v-else
          class="py-8 text-center text-sm text-muted-foreground"
        >
          <template v-if="chartMode === 'variant' && variantFocus !== 'top' && variantFocus !== 'all'">
            Sin tallas en este foco con ventas en el periodo.
          </template>
          <template v-else>
            Sin ventas en el periodo.
          </template>
        </p>

        <div
          v-if="chartMode === 'variant' && variantLegendItems.length"
          class="mt-2 max-h-28 space-y-1.5 overflow-y-auto"
        >
          <p
            v-if="stockoutCutSummary.length"
            class="text-[10px] leading-snug text-rose-800"
          >
            <span class="font-semibold">Fin de stock</span>
            (última venta del periodo):
            <span
              v-for="(item, i) in stockoutCutSummary"
              :key="item.variant_id"
            >
              <span
                class="inline-block h-1.5 w-1.5 translate-y-[-1px] rounded-full"
                :style="{ backgroundColor: item.color }"
              />
              <span class="font-medium">{{ item.label }}</span>
              → {{ formatCutDate(item.cutDate) }}<template v-if="i < stockoutCutSummary.length - 1"> · </template>
            </span>
          </p>
          <div class="flex flex-wrap gap-1">
            <span
              v-for="item in variantLegendItems"
              :key="item.variant_id"
              class="inline-flex max-w-full items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px]"
              :class="{
                'border-rose-200 bg-rose-50 text-rose-900': item.status === 'stockout',
                'border-amber-200 bg-amber-50 text-amber-900': item.status === 'at_risk',
                'border-slate-200 bg-white text-slate-700': item.status === 'ok' || item.status === 'unknown',
              }"
              :title="item.sku || item.label"
            >
              <span
                class="h-2 w-2 shrink-0 rounded-full"
                :style="{ backgroundColor: item.color }"
              />
              <span class="truncate font-medium">{{ item.label }}</span>
              <span
                v-if="item.status === 'stockout' && item.cutDate"
                class="shrink-0 tabular-nums opacity-80"
              >
                · agotó {{ formatCutDate(item.cutDate) }}
              </span>
              <span
                v-else-if="item.status === 'at_risk'"
                class="shrink-0 opacity-80"
              >
                · riesgo
              </span>
            </span>
          </div>
        </div>

        <p
          v-if="stockImpactLine"
          class="mt-2 text-[10px] leading-snug text-muted-foreground"
        >
          {{ stockImpactLine }}
        </p>
      </Card>

      <div class="space-y-1.5">
        <div class="flex items-baseline justify-between gap-2">
          <h3 class="text-[13px] font-semibold tracking-tight text-slate-900">
            Órdenes recientes
          </h3>
          <span class="text-[10px] text-muted-foreground">
            {{ orders.length }} mostradas
          </span>
        </div>

        <div
          v-if="!orders.length"
          class="rounded-xl border border-dashed border-slate-200 px-3 py-6 text-center text-[12px] text-muted-foreground"
        >
          Sin órdenes de este producto en el periodo.
        </div>
        <div
          v-else
          class="overflow-hidden rounded-xl border border-slate-200/70"
        >
          <button
            v-for="row in orders"
            :key="row.order_id"
            type="button"
            class="flex w-full items-center gap-2 border-b border-slate-100 px-3 py-2.5 text-left last:border-b-0 hover:bg-slate-50/80"
            @click="openOrder(row.order_id)"
          >
            <div class="min-w-0 flex-1 space-y-0.5">
              <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-[12px] font-semibold tabular-nums text-slate-900">
                  #{{ row.external_order_id || row.order_id }}
                </span>
                <OrderStatusPill
                  :status="row.status"
                  :outcome="row.post_sale_outcome"
                  compact
                  class="origin-left scale-90"
                />
              </div>
              <div class="flex flex-wrap items-center gap-1.5 text-[10px] text-muted-foreground">
                <span
                  v-if="row.ordered_at"
                  :title="formatDateTime(row.ordered_at)"
                >
                  {{ formatRelativeShort(row.ordered_at) }}
                </span>
                <span v-if="row.buyer_external_id">· {{ row.buyer_external_id }}</span>
                <ConnectionChip
                  v-if="row.connection"
                  :connection="row.connection"
                  class="max-w-[6.5rem]"
                />
              </div>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-[12px] font-semibold tabular-nums text-slate-900">
                <MoneyText
                  :amount="row.line_total"
                  :currency="row.currency || currency"
                />
              </p>
              <p class="text-[10px] tabular-nums text-muted-foreground">
                {{ row.units }} u
              </p>
            </div>
          </button>
        </div>
      </div>
    </template>

    <OrderDetailSlideOver
      :show="orderSlideOpen"
      :order-id="selectedOrderId"
      @close="orderSlideOpen = false"
    />

    <AssortmentForecastSlideOver
      :show="assortmentSlideOpen"
      :stock="stock"
      @close="assortmentSlideOpen = false"
    />
  </div>
</template>
