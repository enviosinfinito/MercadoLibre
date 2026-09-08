<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="adminWorkspacesFilterSchema"
    :route-url="routeUrl"
    search-key="search"
    search-placeholder="Search name or slug…"
    filters-slide-title="Filtros"
    :show-filters-button="false"
  />
</template>

<script setup>
import { computed } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { adminWorkspacesFilterSchema } from '@/lib/filterEngine/schemas/adminWorkspaces'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  routeUrl: { type: String, default: () => route('admin.workspaces.index') },
})

const normalizedFilters = computed(() => ({
  search: props.filters.search ?? '',
}))
</script>
