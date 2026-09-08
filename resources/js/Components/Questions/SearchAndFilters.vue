<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="questionsFilterSchema"
    :route-url="routeUrl"
    :get-catalogs="() => ({ connections })"
    search-key="q"
    search-placeholder="Buscar texto, ítem, comprador…"
    filters-slide-title="Filtros de preguntas"
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
      </div>
    </template>
  </ListSimpleSearchAndFilters>
</template>

<script setup>
import { computed } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { questionsFilterSchema } from '@/lib/filterEngine/schemas/questions'
import { connectionFilterLabel } from '@/lib/connectionLabel'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  connections: { type: Array, default: () => [] },
  routeUrl: { type: String, default: () => route('questions.index') },
})

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  connection_id: props.filters.connection_id != null && props.filters.connection_id !== ''
    ? String(props.filters.connection_id)
    : '',
  tab: props.filters.tab ?? 'all',
}))
</script>
