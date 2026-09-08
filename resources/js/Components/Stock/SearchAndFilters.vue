<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="stockFilterSchema"
    :route-url="routeUrl"
    :get-catalogs="() => ({ warehouses })"
    :show-filters-button="showInternalFilters"
    search-key="q"
    search-placeholder="SKU, título o item id…"
    filters-slide-title="Filtros de stock"
  >
    <template #filters-panel="{ localFilters }">
      <div
        v-if="showInternalFilters"
        class="space-y-4"
      >
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
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            class="rounded border-neutral-300"
            :checked="Boolean(localFilters.low_stock)"
            @change="localFilters.low_stock = $event.target.checked"
          />
          Bajo stock (menos de 14 días)
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            class="rounded border-neutral-300"
            :checked="Boolean(localFilters.unmatched)"
            @change="localFilters.unmatched = $event.target.checked"
          />
          Sin match
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            class="rounded border-neutral-300"
            :checked="Boolean(localFilters.channel_mismatch)"
            @change="localFilters.channel_mismatch = $event.target.checked"
          />
          Desfase canal
        </label>
      </div>
    </template>
  </ListSimpleSearchAndFilters>
</template>

<script setup>
import { computed } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { stockFilterSchema } from '@/lib/filterEngine/schemas/stock'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  warehouses: { type: Array, default: () => [] },
  routeUrl: { type: String, default: () => route('stock.index') },
})

const showInternalFilters = computed(() => (props.filters.tab ?? 'internal') === 'internal')

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  tab: props.filters.tab ?? 'internal',
  warehouse_id: props.filters.warehouse_id != null && props.filters.warehouse_id !== ''
    ? String(props.filters.warehouse_id)
    : '',
  low_stock: Boolean(props.filters.low_stock),
  unmatched: Boolean(props.filters.unmatched),
  channel_mismatch: Boolean(props.filters.channel_mismatch),
}))
</script>
