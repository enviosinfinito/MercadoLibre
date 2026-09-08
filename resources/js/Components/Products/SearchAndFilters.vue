<template>
  <ListSimpleSearchAndFilters
    :filters="normalizedFilters"
    :schema="productsFilterSchema"
    search-key="q"
    search-placeholder="Buscar productos…"
    filters-slide-title="Filtros de productos"
    @apply-filters="onApply"
  >
    <template #filters-panel="{ localFilters }">
      <div class="space-y-3">
        <label class="flex items-center gap-2 text-[12px] text-neutral-700">
          <input
            type="checkbox"
            class="size-3.5 rounded border-neutral-300"
            :checked="Boolean(localFilters.archived)"
            @change="localFilters.archived = $event.target.checked"
          >
          Archivados
        </label>
        <label class="flex items-center gap-2 text-[12px] text-neutral-700">
          <input
            type="checkbox"
            class="size-3.5 rounded border-neutral-300"
            :checked="Boolean(localFilters.without_cost)"
            @change="localFilters.without_cost = $event.target.checked"
          >
          Sin costo
        </label>
      </div>
    </template>
  </ListSimpleSearchAndFilters>
</template>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { productsFilterSchema } from '@/lib/filterEngine/schemas/products'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  routeUrl: { type: String, default: () => route('products.index') },
})

const emit = defineEmits(['update:q'])

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  archived: Boolean(props.filters.archived),
  without_cost: Boolean(props.filters.without_cost),
}))

function serverPayload(params) {
  return {
    archived: params?.archived ? 1 : undefined,
    without_cost: params?.without_cost ? 1 : undefined,
    page: 1,
  }
}

function serverChanged(params) {
  return (
    Boolean(params?.archived) !== Boolean(props.filters.archived)
    || Boolean(params?.without_cost) !== Boolean(props.filters.without_cost)
  )
}

function onApply(params) {
  emit('update:q', params?.q ?? '')
  if (!serverChanged(params) && (params?.q ?? '') !== (props.filters.q ?? '')) {
    // Solo búsqueda client-side
    return
  }
  if (!serverChanged(params) && (params?.q ?? '') === (props.filters.q ?? '')) {
    return
  }
  router.get(props.routeUrl, serverPayload(params), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}
</script>
