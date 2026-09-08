<script setup lang="ts">
import { computed } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import {
  assortmentHealth,
  formatStockDepletionHint,
  formatStockDepletionLabel,
  stockDepletionSeverity,
  stockVariantUrgencyRank,
} from '@/lib/stockForecast'

const props = defineProps<{
  show: boolean
  stock: Record<string, any> | null | undefined
  productTitle?: string | null
}>()

const emit = defineEmits<{
  close: []
}>()

const forecast = computed(() => props.stock?.forecast || null)
const assortment = computed(() => props.stock?.assortment || null)
const aggregate = computed(() => props.stock?.aggregate_forecast || null)
const health = computed(() => assortmentHealth(assortment.value, forecast.value))

const sortedVariants = computed(() => {
  const rows = Array.isArray(props.stock?.variants) ? props.stock.variants : []
  return [...rows].sort((a, b) => stockVariantUrgencyRank(a) - stockVariantUrgencyRank(b))
})

const bottleneckLabel = computed(() => formatStockDepletionLabel(forecast.value))
const bottleneckHint = computed(() => formatStockDepletionHint(forecast.value, assortment.value))
const bottleneckTone = computed(() => stockDepletionSeverity(forecast.value))

function fmtQty(v: number | string | null | undefined) {
  if (v == null || v === '') return '—'
  return Number(v).toLocaleString('es-MX', { maximumFractionDigits: 2 })
}

function fmtUnitsPerDay(row: { forecast?: { units_per_day?: number } }) {
  const rate = Number(row?.forecast?.units_per_day ?? 0)
  if (!rate) return '—'
  return rate.toLocaleString('es-MX', { maximumFractionDigits: 2 })
}

function rowLabel(row: { forecast?: unknown }) {
  return formatStockDepletionLabel(row?.forecast as any)
}

function rowHint(row: { forecast?: unknown }) {
  return formatStockDepletionHint(row?.forecast as any)
}

function rowTone(row: { forecast?: unknown }) {
  return stockDepletionSeverity(row?.forecast as any)
}

function basisLabel(basis: string | null | undefined) {
  if (basis === 'channel') return 'Stock canal (ML)'
  if (basis === 'internal') return 'Stock interno'
  return '—'
}
</script>

<template>
  <SlideOverShell
    :show="show"
    stackable
    fill-height
    close-only-header
    mobile-full-bleed
    accessibility-title="Salud del surtido"
    states-content-class="flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain"
    @close="emit('close')"
  >
    <template #header>
      <div class="min-w-0 pr-2">
        <p
          class="truncate text-sm font-semibold"
          :class="{
            'text-rose-800': health.level === 'danger',
            'text-amber-800': health.level === 'attention',
            'text-emerald-800': health.level === 'healthy',
            'text-slate-800': health.level === 'unknown',
          }"
        >
          Surtido: {{ health.title }}
        </p>
        <p class="truncate text-[11px] text-slate-500">
          {{ productTitle || 'Detalle por talla' }}
        </p>
      </div>
    </template>

    <template #header-actions>
      <span
        class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
        :class="{
          'bg-rose-100 text-rose-900': health.level === 'danger',
          'bg-amber-100 text-amber-900': health.level === 'attention',
          'bg-emerald-100 text-emerald-900': health.level === 'healthy',
          'bg-slate-100 text-slate-700': health.level === 'unknown',
        }"
      >
        {{ health.title }}
      </span>
    </template>

    <div class="px-4 py-4 pb-6 sm:px-6">
      <p class="mb-3 text-[12px] text-slate-600">
        {{ health.subtitle }}
      </p>

      <div class="mb-4 grid grid-cols-2 gap-2">
        <div class="rounded-xl border border-slate-200/80 bg-slate-50/80 px-3 py-2.5">
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Cuello de botella
          </p>
          <p
            class="mt-1 text-xl font-semibold tabular-nums"
            :class="{
              'text-rose-700': bottleneckTone === 'critical',
              'text-amber-700': bottleneckTone === 'warning',
              'text-slate-900': !bottleneckTone,
            }"
          >
            {{ bottleneckLabel }}
          </p>
          <p class="mt-0.5 text-[10px] text-muted-foreground">
            {{ bottleneckHint || 'Min cobertura entre tallas' }}
          </p>
        </div>
        <div class="rounded-xl border border-slate-200/80 bg-slate-50/80 px-3 py-2.5">
          <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Si el stock fuera fungible
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-slate-700">
            {{ formatStockDepletionLabel(aggregate) }}
          </p>
          <p class="mt-0.5 text-[10px] text-muted-foreground">
            Suma canal ÷ ritmo producto (referencia; no es el headline)
          </p>
        </div>
      </div>

      <div class="mb-4 flex flex-wrap gap-1.5">
        <Badge
          v-if="(assortment?.stockout_count ?? 0) > 0"
          variant="danger"
          class="h-5 rounded-full px-2 text-[10px]"
        >
          {{ assortment.stockout_count }} agotada{{ assortment.stockout_count === 1 ? '' : 's' }}
        </Badge>
        <Badge
          v-if="(assortment?.at_risk_count ?? 0) > 0"
          variant="warning"
          class="h-5 rounded-full px-2 text-[10px]"
        >
          {{ assortment.at_risk_count }} en riesgo (menos de 14d)
        </Badge>
        <Badge
          v-if="(assortment?.ok_count ?? 0) > 0"
          variant="muted"
          class="h-5 rounded-full px-2 text-[10px]"
        >
          {{ assortment.ok_count }} ok (≥14d)
        </Badge>
        <Badge
          v-if="(assortment?.no_velocity_count ?? 0) > 0"
          variant="muted"
          class="h-5 rounded-full px-2 text-[10px]"
        >
          {{ assortment.no_velocity_count }} sin ritmo
        </Badge>
      </div>

      <div class="mb-3 rounded-xl border border-slate-200/70 px-3 py-2 text-[11px] text-slate-600">
        <p>
          <span class="font-medium text-slate-800">Base:</span>
          {{ basisLabel(forecast?.stock_basis) }}
        </p>
        <p class="mt-0.5">
          Ritmo por talla = unidades atribuidas (variant / variación ML) ÷ ventana
          (14d, o 90d si no hubo ventas recientes).
        </p>
        <p
          v-if="assortment?.bottleneck_sku"
          class="mt-0.5"
        >
          <span class="font-medium text-slate-800">Peor talla:</span>
          {{ assortment.bottleneck_sku }}
        </p>
      </div>

      <h3 class="mb-2 text-[13px] font-semibold tracking-tight text-slate-900">
        Por talla
      </h3>
      <div class="overflow-x-auto rounded-xl border border-slate-200/80">
        <table class="w-full text-left text-[11px]">
          <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-muted-foreground">
            <tr>
              <th class="px-2.5 py-1.5 font-medium">SKU</th>
              <th class="px-2.5 py-1.5 font-medium tabular-nums">Canal</th>
              <th class="px-2.5 py-1.5 font-medium tabular-nums">Interno</th>
              <th class="px-2.5 py-1.5 font-medium tabular-nums">u/día</th>
              <th class="px-2.5 py-1.5 font-medium">Se acaba en</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in sortedVariants"
              :key="row.id"
              class="border-t border-slate-100"
            >
              <td class="px-2.5 py-1.5">
                <p class="font-mono text-[11px] font-medium text-slate-900">
                  {{ row.sku || `Variant #${row.id}` }}
                </p>
                <p
                  v-if="row.name"
                  class="truncate text-[10px] text-muted-foreground"
                >
                  {{ row.name }}
                </p>
              </td>
              <td class="px-2.5 py-1.5 tabular-nums text-slate-700">
                {{ row.channel_stock == null ? '—' : fmtQty(row.channel_stock) }}
              </td>
              <td class="px-2.5 py-1.5 tabular-nums text-slate-700">
                {{ fmtQty(row.internal_available) }}
              </td>
              <td class="px-2.5 py-1.5 tabular-nums text-slate-600">
                {{ fmtUnitsPerDay(row) }}
              </td>
              <td
                class="px-2.5 py-1.5 font-medium tabular-nums"
                :class="{
                  'text-rose-700': rowTone(row) === 'critical',
                  'text-amber-700': rowTone(row) === 'warning',
                  'text-slate-800': !rowTone(row),
                }"
                :title="rowHint(row)"
              >
                {{ rowLabel(row) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <template #footer>
      <ActionBar
        preset="slide"
        position="sticky-bottom"
        :show-informative-pages="false"
      >
        <ActionGroup align="end">
          <ActionButton
            type="button"
            variant="secondary"
            @click="emit('close')"
          >
            Cerrar
          </ActionButton>
        </ActionGroup>
      </ActionBar>
    </template>
  </SlideOverShell>
</template>
