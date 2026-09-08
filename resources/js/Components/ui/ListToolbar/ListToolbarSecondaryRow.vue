<template>
  <div
    v-if="visible"
    class="mb-2 hidden md:mb-3 md:block"
  >
    <div class="list-toolbar-card w-full">
      <div class="flex flex-col">
        <!-- Modo 1 fila: selección | métricas | chips | Ver detalle + acciones filtros -->
        <div
          v-if="useSingleRow"
          class="list-toolbar-secondary-stable list-toolbar-secondary-inline flex min-h-10 w-full flex-row items-stretch"
        >
          <div
            v-if="showSelection"
            class="list-toolbar-rail-start list-toolbar-rail-start--divided hidden md:flex"
          >
            <slot name="selection" />
          </div>

          <div
            v-if="showSummary"
            class="list-toolbar-inline-summary shrink-0"
          >
            <ListSummaryZone
              dense
              :show-expand-button="false"
              :items="summaryItems"
              :expanded="summaryExpanded"
              :expandable="summaryExpandable"
            >
              <template v-if="$slots.summary" #inline>
                <slot name="summary" />
              </template>
            </ListSummaryZone>
          </div>

          <div
            v-if="showActiveFilters"
            class="list-toolbar-inline-filters min-w-0 flex-1"
          >
            <ListActiveFiltersZone
              inline
              hide-actions
              :active-filters="activeFilters"
              @remove-filter="$emit('remove-filter', $event)"
              @clear-filters="$emit('clear-filters')"
              @expand-filters="$emit('expand-filters')"
            />
          </div>

          <div class="list-toolbar-rail-end list-toolbar-rail-end--combined">
            <button
              v-if="showSummary && summaryExpandable"
              type="button"
              class="list-toolbar-btn-detail shrink-0"
              :class="summaryExpanded ? 'list-toolbar-btn-detail-active' : ''"
              :aria-expanded="summaryExpanded"
              @click="$emit('toggle-summary-expanded')"
            >
              <span>{{ summaryExpanded ? 'Ocultar' : 'Ver detalle' }}</span>
              <svg
                class="h-3.5 w-3.5 shrink-0 transition-transform duration-300 ease-out"
                :class="{ 'rotate-180': summaryExpanded }"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <template v-if="showActiveFilters">
              <button
                type="button"
                title="Limpiar todo"
                class="list-toolbar-icon-btn list-toolbar-icon-btn-clear"
                @click="$emit('clear-filters')"
              >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <button
                type="button"
                title="Editar filtros"
                class="list-toolbar-icon-btn list-toolbar-icon-btn-expand"
                @click="$emit('expand-filters')"
              >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                </svg>
              </button>
            </template>
          </div>
        </div>

        <!-- Modo 2 filas (muchos filtros o sin combinación) -->
        <template v-else>
          <div
            v-if="showSelection || showSummary"
            class="list-toolbar-secondary-stable flex min-h-10 w-full flex-row items-stretch"
          >
            <div
              v-if="showSelection"
              class="list-toolbar-rail-start hidden md:flex"
              :class="showSummary ? 'list-toolbar-rail-start--divided' : ''"
            >
              <slot name="selection" />
            </div>

            <div
              v-if="showSummary"
              class="list-toolbar-rail-middle min-w-0 flex-1"
            >
              <ListSummaryZone
                :items="summaryItems"
                :expanded="summaryExpanded"
                :expandable="summaryExpandable"
                @toggle-expanded="$emit('toggle-summary-expanded')"
              >
                <template v-if="$slots.summary" #inline>
                  <slot name="summary" />
                </template>
              </ListSummaryZone>
            </div>
          </div>

          <div
            v-if="showActiveFilters"
            class="list-toolbar-zone-filters list-toolbar-zone-filters-row min-w-0"
            :class="(showSelection || showSummary) ? 'list-toolbar-zone-filters-row--stacked' : ''"
          >
            <ListActiveFiltersZone
              :active-filters="activeFilters"
              @remove-filter="$emit('remove-filter', $event)"
              @clear-filters="$emit('clear-filters')"
              @expand-filters="$emit('expand-filters')"
            />
          </div>
        </template>

        <Transition name="list-summary-panel">
          <div
            v-if="showSummary && summaryExpanded && $slots['summary-expanded']"
            class="list-summary-panel-wrap"
          >
            <div class="list-summary-panel-inner">
              <slot name="summary-expanded" />
            </div>
          </div>
        </Transition>
      </div>
    </div>
  </div>

  <section
    v-if="mobileVisible"
    class="list-toolbar-card mb-2 overflow-hidden md:hidden"
    aria-label="Filtros y resumen del listado"
  >
    <div v-if="showActiveFilters" class="border-b border-amber-200/50">
      <ListActiveFiltersZone
        compact
        :active-filters="activeFilters"
        @remove-filter="$emit('remove-filter', $event)"
        @clear-filters="$emit('clear-filters')"
        @expand-filters="$emit('expand-filters')"
      />
    </div>
    <div
      v-if="showSummary"
      class="list-toolbar-mobile-summary-zone"
      :class="showActiveFilters ? 'border-t border-brand-primary/10' : ''"
    >
      <p class="list-toolbar-mobile-summary-text">
        <slot name="summary-mobile">
          <template v-for="(item, idx) in summaryItems" :key="'m-sum-' + item.type + '-' + idx">
            <span v-if="idx > 0" class="list-toolbar-summary-sep" aria-hidden="true"> · </span>
            <template v-if="item.type === 'count' || item.type === 'boxes'">
              <span class="list-toolbar-summary-value">{{ item.value }}</span>
              <span class="list-toolbar-summary-unit">{{ ' ' + item.suffix }}</span>
            </template>
            <template v-else>
              <span class="list-toolbar-summary-label">{{ item.label }}</span>
              <span v-if="item.value" class="list-toolbar-summary-value ml-1">{{ item.value }}</span>
            </template>
          </template>
        </slot>
      </p>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import ListActiveFiltersZone from './ListActiveFiltersZone.vue'
import ListSummaryZone from './ListSummaryZone.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  mobileVisible: { type: Boolean, default: false },
  showSelection: { type: Boolean, default: false },
  showActiveFilters: { type: Boolean, default: false },
  showSummary: { type: Boolean, default: false },
  activeFilters: { type: Array, default: () => [] },
  summaryItems: { type: Array, default: () => [] },
  summaryExpanded: { type: Boolean, default: false },
  summaryExpandable: { type: Boolean, default: false },
  /** Con ≤N filtros, compactar a 1 fila en desktop */
  singleRowMaxFilters: { type: Number, default: 3 },
})

defineEmits(['remove-filter', 'clear-filters', 'expand-filters', 'toggle-summary-expanded'])

const useSingleRow = computed(() => {
  if (!props.showActiveFilters) return false
  if (!(props.showSelection || props.showSummary)) return false
  const n = props.activeFilters?.length || 0
  return n > 0 && n <= props.singleRowMaxFilters
})
</script>
