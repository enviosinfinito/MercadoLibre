<template>
  <ListPageToolbar
    v-model:search="searchModel"
    v-model:show-filters="showFilters"
    search-mode="simple"
    :search-placeholder="searchPlaceholder"
    :show-search="showSearch"
    :active-filters="activeFilters"
    :has-active-filters="hasActiveFilters"
    :show-summary-bar="showSummaryBar"
    :summary-items="summaryItems"
    :compact-primary-actions="compactPrimaryActions"
    :filters-slide-title="filtersSlideTitle"
    :show-filters-button="showFiltersButton"
    @search="onSearch"
    @clear-search="onClearSearch"
    @remove-filter="onRemoveFilter"
    @clear-filters="onClearAll"
    @clear-filters-panel="onClearPanel"
    @apply-filters="onApply"
    @open-filters="onOpenFilters"
  >
    <template v-if="$slots['mobile-primary-actions']" #mobile-primary-actions>
      <slot name="mobile-primary-actions" />
    </template>

    <template v-if="$slots['primary-actions'] || $slots['primary-action']" #primary-actions>
      <slot name="primary-actions">
        <slot name="primary-action" />
      </slot>
    </template>

    <template v-if="$slots.summary" #summary>
      <slot name="summary" />
    </template>

    <template v-if="$slots['summary-mobile']" #summary-mobile>
      <slot name="summary-mobile" />
    </template>

    <template #filters-panel>
      <slot name="filters-panel" :local-filters="panelFilters" :draft="panelFilters" />
    </template>

    <template v-if="$slots['filters-footer']" #filters-footer>
      <slot name="filters-footer" />
    </template>
  </ListPageToolbar>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import ListPageToolbar from '@/Components/ui/ListToolbar/ListPageToolbar.vue'
import { useInertiaListFilters } from '@/composables/useInertiaListFilters'
import { createDefaultState } from '@/lib/filterEngine/normalize.js'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  schema: { type: Object, default: null },
  routeUrl: { type: String, default: null },
  searchKey: { type: String, default: 'q' },
  searchPlaceholder: { type: String, default: 'Buscar…' },
  filtersSlideTitle: { type: String, default: 'Filtros' },
  filterLabels: { type: Object, default: () => ({}) },
  filterResolvers: { type: Object, default: () => ({}) },
  getCatalogs: { type: Function, default: () => ({}) },
  excludeKeys: { type: Array, default: () => ['page', 'per_page', 'sort', 'direction'] },
  defaultFilters: { type: Object, default: () => ({}) },
  summaryItems: { type: Array, default: () => [] },
  showSummaryBar: { type: Boolean, default: false },
  compactPrimaryActions: { type: Boolean, default: true },
  showFiltersButton: { type: Boolean, default: true },
  showSearch: { type: Boolean, default: true },
  debounceMs: { type: Number, default: 350 },
  /** Hook antes del navigate (ej. resetAccumulation) */
  beforeApply: { type: Function, default: null },
})

const emit = defineEmits(['apply-filters', 'clear-filters', 'filters-open'])

const showFilters = ref(false)

const listFilters = useInertiaListFilters({
  schema: props.schema,
  routeUrl: props.routeUrl,
  initialFilters: { ...props.defaultFilters, ...props.filters },
  filterLabels: props.filterLabels,
  filterResolvers: props.filterResolvers,
  getCatalogs: props.getCatalogs,
  excludeKeys: props.excludeKeys,
  debounceMs: props.debounceMs,
  onApply: (params) => {
    if (typeof props.beforeApply === 'function') props.beforeApply(params)
    emit('apply-filters', params)
  },
  onClear: () => emit('clear-filters'),
})

const {
  localFilters,
  draft,
  hasActiveFilters,
  activeFilters,
  debouncedApply,
  removeFilter,
  clearAllFilters,
  syncDraftFromState,
  commitDraft,
  clearDraft,
  watchExternalFilters,
  applyFilters,
} = listFilters

if (typeof watchExternalFilters === 'function') {
  watchExternalFilters(() => props.filters)
}

const panelFilters = computed(() => {
  if (draft?.value) return draft.value
  return localFilters.value
})

const searchModel = computed({
  get: () => localFilters.value[props.searchKey] || '',
  set: (value) => {
    localFilters.value = { ...localFilters.value, [props.searchKey]: value }
  },
})

function onSearch() {
  debouncedApply(props.searchKey)
}

function onClearSearch() {
  searchModel.value = ''
  applyFilters({ ...localFilters.value, [props.searchKey]: '' })
}

function onRemoveFilter(filter) {
  removeFilter(filter)
}

function onClearAll() {
  const defaults = props.schema
    ? { ...createDefaultState(props.schema), ...props.defaultFilters }
    : { ...props.defaultFilters }
  clearAllFilters(defaults)
  showFilters.value = false
}

function onClearPanel() {
  if (typeof clearDraft === 'function') {
    clearDraft()
    if (props.defaultFilters && Object.keys(props.defaultFilters).length) {
      Object.assign(draft.value, props.defaultFilters)
    }
    return
  }
  localFilters.value = { ...props.defaultFilters }
}

function onOpenFilters() {
  if (typeof syncDraftFromState === 'function') syncDraftFromState()
  emit('filters-open')
}

function onApply() {
  if (typeof commitDraft === 'function' && props.schema) {
    commitDraft()
  } else {
    emit('apply-filters', { ...localFilters.value })
    if (props.routeUrl) applyFilters({ ...localFilters.value })
  }
  showFilters.value = false
}

watch(showFilters, (open) => {
  if (open) onOpenFilters()
})

defineExpose({
  localFilters,
  draft,
  showFilters,
  applyFilters,
  clearAllFilters,
})
</script>
