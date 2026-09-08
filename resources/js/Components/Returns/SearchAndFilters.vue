<template>
  <ListSimpleSearchAndFilters
    ref="toolbarRef"
    :filters="normalizedFilters"
    :schema="returnsFilterSchema"
    :route-url="routeUrl"
    :get-catalogs="() => ({ connections })"
    :before-apply="beforeApply"
    search-key="q"
    search-placeholder="Buscar SKU, título, Item ID…"
    filters-slide-title="Filtros de devoluciones"
  >
    <template #filters-panel="{ localFilters }">
      <div class="space-y-4">
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Periodo</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.period ?? 'last_30_days'"
            @change="localFilters.period = $event.target.value"
          >
            <option
              v-for="opt in periodOptions"
              :key="opt.value"
              :value="opt.value"
            >
              {{ opt.label }}
            </option>
          </select>
        </div>
        <FilterDateRangeFieldLinked
          v-if="(localFilters.period ?? '') === 'custom'"
          :filter-object="localFilters"
          start-key="from"
          end-key="to"
          label="Rango personalizado"
        />
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            class="rounded border-slate-300"
            :checked="Boolean(localFilters.anomalies_only)"
            @change="localFilters.anomalies_only = $event.target.checked ? '1' : ''"
          >
          Solo productos con anomalía
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            class="rounded border-slate-300"
            :checked="Boolean(localFilters.above_historical)"
            @change="localFilters.above_historical = $event.target.checked ? '1' : ''"
          >
          Sobre promedio histórico
        </label>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Ordenar por</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.sort ?? 'risk_score'"
            @change="localFilters.sort = $event.target.value"
          >
            <option value="risk_score">Risk Score</option>
            <option value="return_rate">Tasa de devolución</option>
            <option value="returned_amount">Importe devuelto</option>
            <option value="returned_units">Unidades devueltas</option>
          </select>
        </div>
      </div>
    </template>
  </ListSimpleSearchAndFilters>
</template>

<script setup>
import { computed } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import FilterDateRangeFieldLinked from '@/Components/ui/FilterDateRangeFieldLinked.vue'
import { returnsFilterSchema } from '@/lib/filterEngine/schemas/returns'

const periodOptions = [
  { value: 'today', label: 'Hoy' },
  { value: 'yesterday', label: 'Ayer' },
  { value: 'last_7_days', label: 'Últimos 7 días' },
  { value: 'last_30_days', label: 'Últimos 30 días' },
  { value: 'this_month', label: 'Este mes' },
  { value: 'previous_month', label: 'Mes anterior' },
  { value: 'last_90_days', label: 'Últimos 90 días' },
  { value: 'year_to_date', label: 'Año actual' },
  { value: 'custom', label: 'Personalizado' },
]

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  connections: { type: Array, default: () => [] },
  routeUrl: { type: String, default: () => route('returns.index') },
  beforeApply: { type: Function, default: null },
})

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  period: props.filters.period ?? 'last_30_days',
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
  sort: props.filters.sort ?? 'risk_score',
  anomalies_only: props.filters.anomalies_only ? '1' : '',
  above_historical: props.filters.above_historical ? '1' : '',
}))
</script>
