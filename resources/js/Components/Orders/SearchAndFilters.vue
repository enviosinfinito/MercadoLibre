<template>
  <ListSimpleSearchAndFilters
    ref="toolbarRef"
    :filters="normalizedFilters"
    :schema="ordersFilterSchema"
    :route-url="routeUrl"
    :get-catalogs="() => ({ connections })"
    :before-apply="beforeApply"
    search-key="q"
    search-placeholder="Buscar por ID, comprador…"
    filters-slide-title="Filtros de órdenes"
  >
    <template #filters-panel="{ localFilters }">
      <div class="space-y-4">
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Canal</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.connection_id ?? ''"
            @change="localFilters.connection_id = $event.target.value"
          >
            <option value="">Todos los canales</option>
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
import { ordersFilterSchema } from '@/lib/filterEngine/schemas/orders'
import { connectionFilterLabel } from '@/lib/connectionLabel'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  connections: { type: Array, default: () => [] },
  routeUrl: { type: String, default: () => route('orders.index') },
  beforeApply: { type: Function, default: null },
})

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  status: props.filters.status ?? '',
  tab: props.filters.tab ?? 'all',
  connection_id: props.filters.connection_id != null && props.filters.connection_id !== ''
    ? String(props.filters.connection_id)
    : '',
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
}))
</script>
