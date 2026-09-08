<template>
  <ListSimpleSearchAndFilters
    :filters="filtersState"
    :schema="shipmentsFilterSchema"
    search-key="q"
    search-placeholder="Buscar envíos…"
    filters-slide-title="Filtros"
    :show-filters-button="false"
    @apply-filters="onApply"
  />
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import ListSimpleSearchAndFilters from '@/Components/ui/ListToolbar/ListSimpleSearchAndFilters.vue'
import { shipmentsFilterSchema } from '@/lib/filterEngine/schemas/shipments'

const props = defineProps({
  modelValue: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'update:q'])

const q = ref(props.modelValue ?? '')

watch(
  () => props.modelValue,
  (v) => {
    if (v !== q.value) q.value = v ?? ''
  },
)

const filtersState = computed(() => ({ q: q.value }))

function onApply(params) {
  const next = params?.q ?? ''
  q.value = next
  emit('update:modelValue', next)
  emit('update:q', next)
}
</script>
