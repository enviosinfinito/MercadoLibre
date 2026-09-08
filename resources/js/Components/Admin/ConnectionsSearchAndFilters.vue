<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="adminConnectionsFilterSchema"
    :route-url="routeUrl"
    search-key="search"
    search-placeholder="Search workspace or external id…"
    filters-slide-title="Filtros de conexiones"
  >
    <template #filters-panel="{ localFilters }">
      <div class="space-y-4">
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Provider</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.provider ?? ''"
            @change="localFilters.provider = $event.target.value"
          >
            <option value="">All providers</option>
            <option value="mercadolibre">mercadolibre</option>
            <option value="amazon">amazon</option>
          </select>
        </div>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Status</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.status ?? ''"
            @change="localFilters.status = $event.target.value"
          >
            <option value="">All statuses</option>
            <option value="pending">pending</option>
            <option value="active">active</option>
            <option value="error">error</option>
            <option value="disabled">disabled</option>
          </select>
        </div>
      </div>
    </template>
  </ListSimpleSearchAndFilters>
</template>

<script setup>
import { computed } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { adminConnectionsFilterSchema } from '@/lib/filterEngine/schemas/adminConnections'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  routeUrl: { type: String, default: () => route('admin.connections.index') },
})

const normalizedFilters = computed(() => ({
  search: props.filters.search ?? '',
  provider: props.filters.provider ?? '',
  status: props.filters.status ?? '',
}))
</script>
