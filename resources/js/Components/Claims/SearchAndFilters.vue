<template>
  <ListSimpleSearchAndFilters
    :filters="filters"
    :schema="claimsFilterSchema"
    :route-url="routeUrl"
    search-key="q"
    search-placeholder="Buscar reclamos…"
    filters-slide-title="Filtros de reclamos"
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
            <option value="opened">Abiertos</option>
            <option value="closed">Cerrados</option>
          </select>
        </div>
      </div>
    </template>
  </ListSimpleSearchAndFilters>
</template>

<script setup>
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { claimsFilterSchema } from '@/lib/filterEngine/schemas/claims'

defineProps({
  filters: { type: Object, default: () => ({}) },
  routeUrl: { type: String, default: () => route('claims.index') },
})
</script>
