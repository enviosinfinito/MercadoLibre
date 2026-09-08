<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="fullOperationsFilterSchema"
    :route-url="routeUrl"
    :get-catalogs="() => ({ connections, operationTypeLabels })"
    :before-apply="beforeApply"
    search-key="q"
    search-placeholder="SKU / inventory_id…"
    filters-slide-title="Filtros Movimientos Full"
  >
    <template #filters-panel="{ localFilters }">
      <div class="space-y-4">
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Tipo</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.operation_type ?? ''"
            @change="localFilters.operation_type = $event.target.value"
          >
            <option value="">Todos</option>
            <option
              v-for="t in operationTypes"
              :key="t"
              :value="t"
            >
              {{ operationTypeLabels[t] ?? t }}
            </option>
          </select>
        </div>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Conexión</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.connection_id ?? ''"
            :style="connectionSelectStyle(localFilters.connection_id)"
            @change="localFilters.connection_id = $event.target.value"
          >
            <option value="">Todas</option>
            <option
              v-for="c in connections"
              :key="c.id"
              :value="String(c.id)"
            >
              {{ connectionFilterLabel(c) }}
            </option>
          </select>
        </div>
        <FilterDateRangeFieldLinked
          :filter-object="localFilters"
          start-key="from"
          end-key="to"
          label="Período"
        />
      </div>
    </template>
  </ListSimpleSearchAndFilters>
</template>

<script setup>
import { computed } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import FilterDateRangeFieldLinked from '@/Components/ui/FilterDateRangeFieldLinked.vue'
import { fullOperationsFilterSchema } from '@/lib/filterEngine/schemas/fullOperations'
import { connectionFilterLabel } from '@/lib/connectionLabel'
import { resolveConnectionColor } from '@/lib/connectionColor'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  connections: { type: Array, default: () => [] },
  operationTypes: { type: Array, default: () => [] },
  operationTypeLabels: { type: Object, default: () => ({}) },
  routeUrl: { type: String, default: () => route('inventory.full-operations.index') },
  beforeApply: { type: Function, default: null },
})

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  variant_id: props.filters.variant_id != null && props.filters.variant_id !== ''
    ? String(props.filters.variant_id)
    : '',
  tab: props.filters.tab || 'all',
  operation_type: props.filters.operation_type ?? '',
  connection_id:
    props.filters.connection_id != null && props.filters.connection_id !== ''
      ? String(props.filters.connection_id)
      : '',
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
}))

function connectionSelectStyle(connectionId) {
  if (!connectionId) return undefined
  const c = props.connections.find((x) => String(x.id) === String(connectionId))
  if (!c?.color) return undefined
  const color = resolveConnectionColor(c.color)
  return {
    borderColor: color,
    boxShadow: `inset 3px 0 0 ${color}`,
  }
}
</script>
