<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="adminUsersFilterSchema"
    :route-url="routeUrl"
    search-key="search"
    search-placeholder="Search name or email…"
    filters-slide-title="Filtros"
    :show-filters-button="false"
  />
</template>

<script setup>
import { computed } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { adminUsersFilterSchema } from '@/lib/filterEngine/schemas/adminUsers'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  routeUrl: { type: String, default: () => route('admin.users.index') },
})

const normalizedFilters = computed(() => ({
  search: props.filters.search ?? '',
}))
</script>
