<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="ledgerFilterSchema"
    :route-url="routeUrl"
    :get-catalogs="() => ({ warehouses })"
    search-key="q"
    search-placeholder="SKU…"
    filters-slide-title="Filtros de movimientos"
  >
    <template #filters-panel="{ localFilters }">
      <div class="space-y-4">
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Tipo</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.movement_type ?? ''"
            @change="localFilters.movement_type = $event.target.value"
          >
            <option value="">Todos</option>
            <option
              v-for="t in movementTypes"
              :key="t"
              :value="t"
            >
              {{ t }}
            </option>
          </select>
        </div>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Almacén</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.warehouse_id ?? ''"
            @change="localFilters.warehouse_id = $event.target.value"
          >
            <option value="">Todos</option>
            <option
              v-for="w in warehouses"
              :key="w.id"
              :value="String(w.id)"
            >
              {{ w.code }}
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
import { ledgerFilterSchema } from '@/lib/filterEngine/schemas/ledger'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  warehouses: { type: Array, default: () => [] },
  movementTypes: { type: Array, default: () => [] },
  routeUrl: { type: String, default: () => route('inventory.ledger.index') },
})

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  variant_id: props.filters.variant_id != null && props.filters.variant_id !== ''
    ? String(props.filters.variant_id)
    : '',
  movement_type: props.filters.movement_type ?? '',
  warehouse_id: props.filters.warehouse_id != null && props.filters.warehouse_id !== ''
    ? String(props.filters.warehouse_id)
    : '',
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
}))
</script>
