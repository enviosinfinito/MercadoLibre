<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="publicationsFilterSchema"
    :route-url="routeUrl"
    :get-catalogs="() => ({ connections })"
    search-key="q"
    search-placeholder="Título o item id…"
    filters-slide-title="Filtros de publicaciones"
  >
    <template #filters-panel="{ localFilters }">
      <div class="space-y-4">
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Estado</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.status ?? ''"
            @change="localFilters.status = $event.target.value"
          >
            <option value="">Todos los estados</option>
            <option value="active">Activa</option>
            <option value="paused">Pausada</option>
          </select>
        </div>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Match</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.matched && localFilters.matched !== 'any' ? localFilters.matched : ''"
            @change="localFilters.matched = $event.target.value"
          >
            <option value="">Match: todos</option>
            <option value="yes">Con match</option>
            <option value="no">Sin match</option>
          </select>
        </div>
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
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            class="rounded border-neutral-300"
            :checked="Boolean(localFilters.without_cost)"
            @change="localFilters.without_cost = $event.target.checked"
          />
          Sin costo
        </label>
      </div>
    </template>
  </ListSimpleSearchAndFilters>
</template>

<script setup>
import { computed } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { publicationsFilterSchema } from '@/lib/filterEngine/schemas/publications'
import { connectionFilterLabel } from '@/lib/connectionLabel'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  connections: { type: Array, default: () => [] },
  routeUrl: { type: String, default: () => route('publications.index') },
})

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  status: props.filters.status ?? '',
  connection_id: props.filters.connection_id != null && props.filters.connection_id !== ''
    ? String(props.filters.connection_id)
    : '',
  matched: props.filters.matched && props.filters.matched !== 'any' ? props.filters.matched : '',
  without_cost: Boolean(props.filters.without_cost),
}))
</script>
