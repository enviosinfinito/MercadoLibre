<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="analyticsFilterSchema"
    :route-url="routeUrl"
    :get-catalogs="() => ({ connections })"
    :show-filters-button="true"
    :show-search="false"
    :show-summary-bar="false"
    filters-slide-title="Filtros del dashboard"
  >
    <template #filters-panel="{ localFilters }">
      <div class="space-y-4">
        <FilterDateRangeFieldLinked
          :filter-object="localFilters"
          start-key="date_from"
          end-key="date_to"
          label="Período"
        />
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Campo fecha</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.date_field || 'ordered_at'"
            @change="localFilters.date_field = $event.target.value"
          >
            <option value="ordered_at">ordered_at</option>
            <option value="created_at">created_at</option>
            <option value="paid_at">paid_at</option>
          </select>
        </div>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Conexión</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="localFilters.connection_id ?? ''"
            @change="localFilters.connection_id = $event.target.value"
          >
            <option value="">Todas</option>
            <option
              v-for="c in connections"
              :key="c.id"
              :value="String(c.id)"
            >
              #{{ c.id }} {{ c.provider }} {{ c.external_user_id || '' }}
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
import FilterDateRangeFieldLinked from '@/Components/ui/FilterDateRangeFieldLinked.vue'
import { analyticsFilterSchema } from '@/lib/filterEngine/schemas/analytics'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  connections: { type: Array, default: () => [] },
  dashboardId: { type: Number, required: true },
})

const routeUrl = computed(() => route('analytics.dashboards.show', props.dashboardId))

const normalizedFilters = computed(() => ({
  date_from: props.filters.date_from ?? '',
  date_to: props.filters.date_to ?? '',
  connection_id: props.filters.connection_id != null && props.filters.connection_id !== ''
    ? String(props.filters.connection_id)
    : '',
  date_field: props.filters.date_field || 'ordered_at',
}))
</script>
