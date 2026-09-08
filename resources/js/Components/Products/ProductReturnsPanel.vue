<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import Card from '@/Components/ui/Card.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import DashboardChart from '@/Components/Dashboard/DashboardChart.vue'
import OrderDetailSlideOver from '@/Components/Orders/OrderDetailSlideOver.vue'
import ReturnRiskBadge from '@/Components/Returns/ReturnRiskBadge.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime } from '@/lib/utils'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart, PieChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  MarkLineComponent,
} from 'echarts/components'

use([
  CanvasRenderer,
  LineChart,
  PieChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  MarkLineComponent,
])

const props = withDefaults(
  defineProps<{
    returnsKey: string | null
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

const emit = defineEmits<{
  openOrder: [orderId: number]
}>()

const loading = ref(false)
const error = ref<string | null>(null)
const payload = ref<Record<string, any> | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)
const followStatus = ref('review')
const followNotes = ref('')
const followActionTaken = ref('')
const orderSlideOpen = ref(false)
const selectedOrderId = ref<number | null>(null)

const product = computed(() => payload.value?.detail?.product || {})
const brand = '#0f766e'

function ratePct(rate: number) {
  return `${((rate || 0) * 100).toFixed(1)}%`
}

function outcomeLabel(outcome: string | null) {
  switch (outcome) {
    case 'returned':
      return 'Devuelto'
    case 'refunded':
      return 'Reembolsado'
    case 'partial_refunded':
      return 'Parcial'
    default:
      return outcome || '—'
  }
}

function buildUrl(key: string) {
  const params: Record<string, unknown> = {
    product: key,
    period: props.period || 'last_30_days',
  }
  if (props.connectionIds?.length) {
    params.connection_ids = props.connectionIds
  }
  return route('returns.products.show', params)
}

async function load() {
  if (!props.returnsKey) {
    payload.value = null
    return
  }
  loading.value = true
  error.value = null
  try {
    const data = await fetchSlidePayload(buildUrl(props.returnsKey), { cache: 'no-store' })
    payload.value = data
    followStatus.value = data.action?.status || 'review'
    followNotes.value = ''
    followActionTaken.value = data.action?.action_taken || ''
  } catch (e: any) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar devoluciones.'
    payload.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.active, props.returnsKey, props.period, props.connectionIds] as const,
  ([active, key]) => {
    if (active && key) void load()
    if (!key) {
      payload.value = null
      error.value = null
    }
  },
  { immediate: true },
)

const seriesChart = computed(() => {
  const series = payload.value?.detail?.series || []
  const hist =
    payload.value?.detail?.historical_avg != null
      ? +(payload.value.detail.historical_avg * 100).toFixed(2)
      : null
  return {
    color: [brand, '#0284c7', '#d97706'],
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
        axisLabel: { color: '#94a3b8', fontSize: 9, formatter: '{value}%' },
      },
      {
        type: 'value',
        splitLine: { show: false },
        axisLabel: { color: '#94a3b8', fontSize: 9 },
      },
    ],
    series: [
      {
        name: 'Tasa %',
        type: 'line',
        smooth: true,
        showSymbol: false,
        data: series.map((s: any) => +((s.return_rate || 0) * 100).toFixed(2)),
        markLine:
          hist != null
            ? {
                symbol: 'none',
                lineStyle: { color: '#94a3b8', type: 'dashed', width: 1 },
                label: { formatter: 'Histórico', fontSize: 9, color: '#64748b' },
                data: [{ yAxis: hist }],
              }
            : undefined,
      },
      {
        name: 'Vendidas',
        type: 'line',
        yAxisIndex: 1,
        smooth: true,
        showSymbol: false,
        data: series.map((s: any) => s.units_sold),
      },
      {
        name: 'Devueltas',
        type: 'line',
        yAxisIndex: 1,
        smooth: true,
        showSymbol: false,
        data: series.map((s: any) => s.returned_units),
      },
    ],
  }
})

const reasonChart = computed(() => ({
  color: [brand, '#0284c7', '#d97706', '#7c3aed', '#dc2626', '#94a3b8'],
  tooltip: {
    trigger: 'item',
    backgroundColor: 'rgba(255,255,255,0.96)',
    borderColor: '#e2e8f0',
    textStyle: { fontSize: 11, color: '#0f172a' },
  },
  legend: {
    bottom: 0,
    type: 'scroll',
    textStyle: { fontSize: 10, color: '#64748b' },
    itemWidth: 8,
    itemHeight: 8,
  },
  series: [
    {
      type: 'pie',
      radius: ['42%', '68%'],
      center: ['50%', '46%'],
      itemStyle: { borderRadius: 4, borderColor: '#fff', borderWidth: 2 },
      label: { show: false },
      data: (payload.value?.detail?.reasons || []).map((r: any) => ({
        name: r.label,
        value: r.count,
      })),
    },
  ],
}))

function openOrder(orderId: number | null | undefined) {
  if (!orderId) return
  selectedOrderId.value = Number(orderId)
  orderSlideOpen.value = true
  emit('openOrder', Number(orderId))
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

async function submitFollowUp() {
  if (!props.returnsKey || saving.value) return
  saving.value = true
  saveError.value = null
  try {
    const res = await fetch(route('returns.products.actions', props.returnsKey), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        status: followStatus.value,
        notes: followNotes.value || null,
        action_taken: followActionTaken.value || null,
      }),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || `Error ${res.status}`)
    if (payload.value) {
      payload.value = { ...payload.value, action: data.action ?? payload.value.action }
    }
    followNotes.value = ''
  } catch (e: any) {
    saveError.value = typeof e?.message === 'string' ? e.message : 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}

defineExpose({ reload: load })
</script>

<template>
  <div class="space-y-3 p-3 sm:p-4">
    <div
      v-if="loading"
      class="py-16 text-center text-sm text-muted-foreground"
    >
      Cargando devoluciones…
    </div>
    <div
      v-else-if="!returnsKey"
      class="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-muted-foreground"
    >
      Sin clave de producto para analizar devoluciones.
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
      <div class="flex flex-wrap items-center gap-1.5">
        <ReturnRiskBadge
          :level="product.risk_level"
          :score="product.risk_score"
        />
        <Badge
          variant="outline"
          class="text-[10px]"
        >
          Confianza: {{ product.confidence || '—' }}
        </Badge>
        <Badge
          v-if="product.insufficient_sample"
          variant="muted"
          class="text-[10px]"
        >
          Muestra insuficiente
        </Badge>
        <span class="text-[10px] text-muted-foreground">
          {{ payload.period?.label || period }}
        </span>
      </div>

      <div class="grid gap-2 sm:grid-cols-2">
        <Card
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Tasa
          </p>
          <p class="mt-1 text-xl font-semibold tracking-tight">
            {{ ratePct(product.return_rate) }}
          </p>
          <p class="mt-0.5 text-[10px] text-muted-foreground">
            Histórico: {{ ratePct(payload.detail?.historical_avg || 0) }}
          </p>
        </Card>
        <Card
          class="rounded-xl border-slate-200/70"
          content-class="p-3"
        >
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            $ Devuelto
          </p>
          <p class="mt-1 text-xl font-semibold tracking-tight">
            <MoneyText :amount="product.returned_amount" />
          </p>
          <p class="mt-0.5 text-[10px] text-muted-foreground">
            {{ product.return_count ?? 0 }} casos · {{ product.returned_units ?? 0 }}/{{ product.units_sold ?? 0 }} u
          </p>
        </Card>
      </div>

      <Card
        class="rounded-xl border-slate-200/70"
        content-class="space-y-2 p-3"
      >
        <h3 class="text-[13px] font-semibold tracking-tight">
          Por qué lo devuelven
        </h3>
        <p class="text-[12px] leading-relaxed text-slate-700">
          {{ product.narrative || 'Aún no hay suficiente texto de compradores para un resumen.' }}
        </p>
      </Card>

      <Card
        class="rounded-xl border-slate-200/70"
        content-class="p-3"
      >
        <h3 class="mb-1 text-[13px] font-semibold tracking-tight">
          Evolución
        </h3>
        <DashboardChart
          v-if="(payload.detail?.series || []).length"
          :option="seriesChart"
          height="220px"
        />
        <p
          v-else
          class="py-8 text-center text-sm text-muted-foreground"
        >
          Sin serie diaria.
        </p>
      </Card>

      <Card
        class="rounded-xl border-slate-200/70"
        content-class="p-3"
      >
        <h3 class="mb-1 text-[13px] font-semibold tracking-tight">
          Motivos
        </h3>
        <DashboardChart
          v-if="(payload.detail?.reasons || []).length"
          :option="reasonChart"
          height="220px"
        />
        <p
          v-else
          class="py-8 text-center text-sm text-muted-foreground"
        >
          Sin motivos.
        </p>
      </Card>

      <Card
        class="rounded-xl border-slate-200/70"
        content-class="p-0"
      >
        <div class="border-b border-slate-100 px-3 py-2.5">
          <h3 class="text-[13px] font-semibold tracking-tight">
            Variantes
          </h3>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50/80 text-left text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            <tr>
              <th class="px-3 py-2">Variante</th>
              <th class="px-3 py-2">Ventas</th>
              <th class="px-3 py-2">Dev.</th>
              <th class="px-3 py-2">Tasa</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="v in payload.detail?.variants || []"
              :key="`${v.variant_id}-${v.ml_variation_id}`"
              class="border-t border-slate-100"
            >
              <td class="px-3 py-2 text-[12px]">
                {{ v.label }}
              </td>
              <td class="px-3 py-2 tabular-nums">
                {{ v.units_sold }}
              </td>
              <td class="px-3 py-2 tabular-nums">
                {{ v.returned_units }}
              </td>
              <td class="px-3 py-2 font-medium tabular-nums">
                {{ ratePct(v.return_rate) }}
              </td>
            </tr>
            <tr v-if="!(payload.detail?.variants || []).length">
              <td
                colspan="4"
                class="px-3 py-6 text-center text-sm text-muted-foreground"
              >
                Sin desglose por variante.
              </td>
            </tr>
          </tbody>
        </table>
      </Card>

      <Card
        class="rounded-xl border-slate-200/70"
        content-class="space-y-2 p-3"
      >
        <h3 class="text-[13px] font-semibold tracking-tight">
          Casos recientes
        </h3>
        <div
          v-for="c in payload.detail?.cases || []"
          :key="c.id"
          class="rounded-lg border border-slate-100 px-3 py-2"
        >
          <div class="flex items-center justify-between gap-2">
            <p class="text-[12px] font-medium">
              {{ c.inferred_reason_label || 'Sin motivo' }}
            </p>
            <Badge
              variant="outline"
              class="text-[10px]"
            >
              {{ outcomeLabel(c.outcome) }}
            </Badge>
          </div>
          <p class="mt-1 line-clamp-2 text-[11px] text-muted-foreground">
            {{ c.analysis_summary || 'Sin análisis' }}
          </p>
          <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[10px] text-muted-foreground">
            <span>{{ formatDateTime(c.opened_at) }}</span>
            <button
              v-if="c.order_id"
              type="button"
              class="font-semibold text-brand hover:underline"
              @click="openOrder(c.order_id)"
            >
              Ver orden
            </button>
          </div>
        </div>
        <p
          v-if="!(payload.detail?.cases || []).length"
          class="py-4 text-center text-sm text-muted-foreground"
        >
          Sin casos en el periodo.
        </p>
      </Card>

      <Card
        class="rounded-xl border-slate-200/70"
        content-class="space-y-2 p-3"
      >
        <h3 class="text-[13px] font-semibold tracking-tight">
          Seguimiento
        </h3>
        <select
          v-model="followStatus"
          class="h-9 w-full rounded-lg border border-slate-200 px-2.5 text-[12px]"
        >
          <option
            v-for="s in payload.action_statuses || []"
            :key="s"
            :value="s"
          >
            {{ s }}
          </option>
        </select>
        <textarea
          v-model="followNotes"
          rows="2"
          placeholder="Nota…"
          class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-[12px]"
        />
        <textarea
          v-model="followActionTaken"
          rows="2"
          placeholder="Acción realizada…"
          class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-[12px]"
        />
        <p
          v-if="saveError"
          class="text-[11px] text-red-600"
        >
          {{ saveError }}
        </p>
        <Button
          size="sm"
          class="h-8 text-[11px]"
          :disabled="saving"
          @click="submitFollowUp"
        >
          {{ saving ? 'Guardando…' : 'Guardar seguimiento' }}
        </Button>
      </Card>
    </template>

    <OrderDetailSlideOver
      :show="orderSlideOpen"
      :order-id="selectedOrderId"
      :connection="product.connection"
      initial-tab="claims"
      @close="orderSlideOpen = false"
    />
  </div>
</template>
