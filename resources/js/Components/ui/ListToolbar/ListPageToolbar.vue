<template>
  <div ref="toolbarAnchorRef">
    <ListToolbarPrimaryRow
      :search-mode="searchMode"
      :search="search"
      :search-placeholder="searchPlaceholder"
      :visible-count="visibleCount"
      :filter-count="resolvedFilterCount"
      :show-selection="showSelectionToolbar"
      :show-mobile-tools="resolvedShowMobileTools"
      :show-search="showSearch"
      :mobile-bulk-select-prompt="mobileBulkSelectPrompt"
      :compact-primary-actions="compactPrimaryActions"
      :show-filters-button="resolvedShowFiltersButton"
      @update:search="$emit('update:search', $event)"
      @search="$emit('search', $event)"
      @clear-search="$emit('clear-search')"
      @open-filters="openFilters"
      @clear-filters="$emit('clear-filters')"
    >
      <template v-if="$slots.search" #search>
        <slot name="search" />
      </template>

      <template v-if="$slots['primary-actions'] || $slots['primary-action']" #primary-actions>
        <slot name="primary-actions">
          <slot name="primary-action" />
        </slot>
      </template>

      <template v-if="$slots['primary-actions-label']" #primary-actions-label>
        <slot name="primary-actions-label" />
      </template>

      <template v-if="$slots['mobile-primary-actions']" #mobile-primary-actions>
        <slot name="mobile-primary-actions" />
      </template>

      <template v-if="$slots['mobile-tools']" #mobile-tools>
        <slot name="mobile-tools" />
      </template>

      <template v-if="$slots['more-actions-trigger']" #more-actions-trigger>
        <slot name="more-actions-trigger" />
      </template>

      <template v-if="$slots['more-actions']" #more-actions>
        <slot name="more-actions" />
      </template>

      <template v-if="showSelectionToolbar" #selection>
        <slot name="selection">
          <ListBulkSelectionToolbar
            v-bind="bulkSelectionProps"
            @update:select-all="$emit('update:selectAll', $event)"
            @select-all-filtered="$emit('select-all-filtered')"
            @clear-selection="$emit('clear-selection')"
          />
        </slot>
      </template>
    </ListToolbarPrimaryRow>

    <ListToolbarSecondaryRow
      :visible="showDesktopSecondaryRow"
      :mobile-visible="showMobileSecondaryRow"
      :show-selection="showSelectionToolbar"
      :show-active-filters="showActiveFiltersBar"
      :show-summary="showSummaryBar"
      :active-filters="activeFilters"
      :summary-items="summaryItems"
      :summary-expanded="summaryExpanded"
      :summary-expandable="summaryExpandable"
      @remove-filter="onRemoveFilter"
      @clear-filters="$emit('clear-filters')"
      @expand-filters="openFilters"
      @toggle-summary-expanded="$emit('toggle-summary-expanded')"
    >
      <template v-if="showSelectionToolbar" #selection>
        <slot name="selection">
          <ListBulkSelectionToolbar
            v-bind="bulkSelectionProps"
            @update:select-all="$emit('update:selectAll', $event)"
            @select-all-filtered="$emit('select-all-filtered')"
            @clear-selection="$emit('clear-selection')"
          />
        </slot>
      </template>

      <template v-if="$slots.summary" #summary>
        <slot name="summary" />
      </template>

      <template v-if="$slots['summary-mobile']" #summary-mobile>
        <slot name="summary-mobile" />
      </template>

      <template v-if="$slots['summary-expanded']" #summary-expanded>
        <slot name="summary-expanded" />
      </template>
    </ListToolbarSecondaryRow>

    <FiltersSlideOver
      v-if="resolvedShowFiltersButton"
      :show="showFilters"
      :title="filtersSlideTitle"
      :minimal-header="filtersMinimalHeader"
      @close="closeFilters"
      @content-scroll="$emit('filters-content-scroll')"
    >
      <template v-if="$slots['filters-active-bar']" #active-bar>
        <slot name="filters-active-bar" />
      </template>

      <slot name="filters-panel" />

      <template v-if="$slots['filters-footer']" #footer>
        <slot name="filters-footer" />
      </template>
      <template v-else-if="showDefaultFiltersFooter" #footer>
        <ActionBar
          preset="slide"
          position="sticky-bottom"
          :show-informative-pages="false"
          class="!border-0 !bg-transparent !px-0 !py-0"
          :secondary="{
            label: 'Limpiar',
            onClick: () => $emit('clear-filters-panel'),
            variant: 'outline',
          }"
          :primary="filtersFooterPrimary"
        />
      </template>
    </FiltersSlideOver>
  </div>

  <Teleport to="body">
    <div
      v-if="stickyMirrorMounted"
      class="fixed right-0 z-30"
      :style="stickyMirrorFrameStyle"
    >
      <ListToolbarStickySelectionBar
        :visible="stickyMirrorVisible"
        :bulk-selection-props="stickyBulkSelectionProps"
        @update:select-all="$emit('update:selectAll', $event)"
        @select-all-filtered="$emit('select-all-filtered')"
        @clear-selection="$emit('clear-selection')"
        @leaved="onStickyMirrorLeaved"
      >
        <template v-if="$slots['more-actions-trigger']" #more-actions-trigger>
          <slot name="more-actions-trigger" />
        </template>
        <template v-if="$slots['more-actions']" #more-actions>
          <slot name="more-actions" />
        </template>
        <template v-if="$slots.selection" #selection>
          <slot name="selection" />
        </template>
      </ListToolbarStickySelectionBar>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, inject, ref, useSlots, watch } from 'vue'
import FiltersSlideOver from '@/Components/ui/FiltersSlideOver.vue'
import { ActionBar } from '@/Components/ui/ActionBar'
import { useStickyWhenOutOfView } from '@/composables/useStickyWhenOutOfView.js'
import ListToolbarPrimaryRow from './ListToolbarPrimaryRow.vue'
import ListToolbarSecondaryRow from './ListToolbarSecondaryRow.vue'
import ListBulkSelectionToolbar from './ListBulkSelectionToolbar.vue'
import ListToolbarStickySelectionBar from './ListToolbarStickySelectionBar.vue'
import './list-toolbar.css'

const slots = useSlots()
const toolbarAnchorRef = ref(null)

const layoutHeaderHeight = inject('layoutHeaderHeight', ref(0))
const layoutSidebarWidth = inject('layoutSidebarWidth', ref(0))

const props = defineProps({
  searchMode: { type: String, default: 'simple' },
  search: { type: String, default: '' },
  searchPlaceholder: { type: String, default: 'Buscar…' },
  visibleCount: { type: [Number, String], default: null },
  activeFilters: { type: Array, default: () => [] },
  filterCount: { type: Number, default: null },
  showFilters: { type: Boolean, default: false },
  filtersSlideTitle: { type: String, default: 'Filtros' },
  filtersMinimalHeader: { type: Boolean, default: true },
  showDefaultFiltersFooter: { type: Boolean, default: true },
  isApplyingFilters: { type: Boolean, default: false },
  showSelectionToolbar: { type: Boolean, default: false },
  /** Si es undefined, el botón Filtros se muestra siempre (salvo showFiltersButton: false) */
  showFiltersButton: { type: Boolean, default: undefined },
  showMobileTools: { type: Boolean, default: true },
  showSearch: { type: Boolean, default: true },
  mobileBulkSelectPrompt: { type: Boolean, default: false },
  /** Botón primario compacto (pill) junto a acciones derechas; false = barra segmentada */
  compactPrimaryActions: { type: Boolean, default: true },
  hasActiveFilters: { type: Boolean, default: false },
  showSummaryBar: { type: Boolean, default: false },
  summaryItems: { type: Array, default: () => [] },
  summaryExpanded: { type: Boolean, default: false },
  summaryExpandable: { type: Boolean, default: false },
  bulkSelectionProps: { type: Object, default: () => ({}) },
  /** Muestra espejo fixed de selección + acciones cuando la toolbar original sale del viewport */
  stickyWhenOutOfView: { type: Boolean, default: false },
})

const emit = defineEmits([
  'update:search',
  'update:showFilters',
  'update:selectAll',
  'search',
  'clear-search',
  'open-filters',
  'close-filters',
  'remove-filter',
  'clear-filters',
  'clear-filters-panel',
  'apply-filters',
  'filters-content-scroll',
  'select-all-filtered',
  'clear-selection',
  'toggle-summary-expanded',
])

const stickyActive = computed(() => {
  if (!props.stickyWhenOutOfView) return false
  const p = props.bulkSelectionProps ?? {}
  return (p.selectedCount > 0) || p.selectAllFiltered === true || p.mobileBulkSelectPrompt === true
})

const { showMirror: showStickyMirror } = useStickyWhenOutOfView({
  targetRef: toolbarAnchorRef,
  enabled: computed(() => props.stickyWhenOutOfView),
  active: stickyActive,
})

const stickyMirrorVisible = computed(
  () => props.stickyWhenOutOfView && showStickyMirror.value
)

const stickyMirrorMounted = ref(false)

watch(
  stickyMirrorVisible,
  (visible) => {
    if (visible) {
      stickyMirrorMounted.value = true
    }
  },
  { immediate: true }
)

function onStickyMirrorLeaved() {
  stickyMirrorMounted.value = false
}

const stickyMirrorFrameStyle = computed(() => ({
  top: `${layoutHeaderHeight.value}px`,
  left: `${layoutSidebarWidth.value}px`,
}))

const stickyBulkSelectionProps = computed(() => ({
  ...props.bulkSelectionProps,
  checkboxIdMobile: 'selectAllMobileSticky',
  checkboxIdDesktop: 'selectAllDesktopSticky',
}))

const resolvedFilterCount = computed(() =>
  props.filterCount != null ? props.filterCount : props.activeFilters.length
)

const resolvedShowFiltersButton = computed(() =>
  props.showFiltersButton !== undefined
    ? props.showFiltersButton
    : true
)

const resolvedShowMobileTools = computed(() => {
  if (!props.showMobileTools) return false

  return resolvedShowFiltersButton.value
    || props.showSelectionToolbar
    || Boolean(slots['mobile-tools'])
    || Boolean(slots['more-actions-trigger'])
    || Boolean(slots['more-actions'])
})

const showActiveFiltersBar = computed(() => props.hasActiveFilters && !props.showFilters)

const showDesktopSecondaryRow = computed(() =>
  showActiveFiltersBar.value || props.showSelectionToolbar || props.showSummaryBar
)

const showMobileSecondaryRow = computed(() =>
  showActiveFiltersBar.value || props.showSummaryBar
)

function openFilters() {
  emit('update:showFilters', true)
  emit('open-filters')
}

function closeFilters() {
  emit('update:showFilters', false)
  emit('close-filters')
}

function onRemoveFilter(filter) {
  emit('remove-filter', filter)
}

const filtersFooterPrimary = computed(() => ({
  label: props.isApplyingFilters ? 'Aplicando...' : 'Aplicar filtros',
  variant: 'brand',
  loading: props.isApplyingFilters,
  disabled: props.isApplyingFilters,
  onClick: onApplyFiltersClick,
}))

function onApplyFiltersClick () {
  emit('apply-filters')
}
</script>
