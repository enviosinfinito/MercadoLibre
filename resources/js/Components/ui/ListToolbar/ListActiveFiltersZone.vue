<template>
  <div
    class="w-full min-w-0"
    :class="[
      compact ? 'px-0 py-1.5' : '',
      inline ? 'list-toolbar-filters-inline-root' : '',
    ]"
  >
    <div
      class="list-toolbar-filters-bar w-full"
      :class="[
        filtersExpanded ? 'list-toolbar-filters-bar--expanded' : 'list-toolbar-filters-bar--collapsed',
        inline ? 'list-toolbar-filters-bar--inline' : '',
      ]"
    >
      <div
        class="list-toolbar-filters-start min-w-0 flex-1"
        :class="[
          inline ? 'list-toolbar-filters-start--inline' : 'list-toolbar-rail-start',
          filtersExpanded ? 'items-start' : 'items-center',
        ]"
      >
        <!-- En inline los chips ya cuentan; badge "N filtros" es redundante -->
        <div
          v-if="!inline"
          class="flex h-7 shrink-0 items-center gap-1.5"
          :title="badgeTitle"
        >
          <svg class="h-3.5 w-3.5 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707l-5.414 5.414A1 1 0 0114 12v5.586l-2 2V12a1 1 0 01-.293-.707L6.293 6.707A1 1 0 016 6V4z" />
          </svg>
          <span class="list-toolbar-filters-badge tabular-nums">
            {{ activeFilters.length }}
            {{ activeFilters.length === 1 ? 'filtro' : 'filtros' }}
          </span>
        </div>
        <span
          v-else
          class="sr-only"
        >{{ badgeTitle }}</span>

        <div
          class="list-toolbar-chips-track min-w-0 flex-1"
          :class="filtersExpanded ? 'list-toolbar-chips-track--expanded' : 'list-toolbar-chips-track--collapsed'"
        >
          <template v-for="filter in displayedFilters" :key="filter.key">
            <div
              class="list-toolbar-chip list-toolbar-chip-filter max-w-[min(100%,14rem)]"
              :title="filter.title || undefined"
            >
              <span v-if="filter.label" class="list-toolbar-chip-filter-label">{{ filter.label }}:</span>
              <span class="truncate">{{ filter.value }}</span>
              <button
                type="button"
                class="list-toolbar-chip-remove"
                :aria-label="filter.title ? `Quitar: ${filter.title}` : 'Quitar filtro'"
                @click="$emit('remove-filter', filter)"
              >
                <svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </template>

          <button
            v-if="hiddenCount > 0 && !filtersExpanded"
            type="button"
            class="list-toolbar-chip list-toolbar-chip-more shrink-0"
            :aria-expanded="false"
            @click="filtersExpanded = true"
          >
            +{{ hiddenCount }} más
          </button>
          <button
            v-else-if="filtersExpanded && activeFilters.length > collapseLimit"
            type="button"
            class="list-toolbar-chip list-toolbar-chip-more shrink-0"
            :aria-expanded="true"
            @click="filtersExpanded = false"
          >
            Mostrar menos
          </button>
        </div>
      </div>

      <div v-if="!hideActions" class="list-toolbar-rail-end">
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
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  activeFilters: { type: Array, default: () => [] },
  /** Móvil: tope más bajo de chips visibles colapsado */
  compact: { type: Boolean, default: false },
  /** Embebido en la fila estable (1 fila desktop) */
  inline: { type: Boolean, default: false },
  /** Acciones en el rail del padre (modo 1 fila) */
  hideActions: { type: Boolean, default: false },
  /** Tope desktop de chips visibles antes de +N (compact usa 2) */
  maxVisible: { type: Number, default: 3 },
})

defineEmits(['remove-filter', 'clear-filters', 'expand-filters'])

const filtersExpanded = ref(false)

const collapseLimit = computed(() => (props.compact ? 2 : props.maxVisible))

const displayedFilters = computed(() => {
  if (filtersExpanded.value) return props.activeFilters
  return props.activeFilters.slice(0, collapseLimit.value)
})

const hiddenCount = computed(() => {
  return Math.max(0, props.activeFilters.length - collapseLimit.value)
})

const badgeTitle = computed(() => {
  const n = props.activeFilters.length
  return n === 1 ? '1 filtro activo' : `${n} filtros activos`
})

watch(
  () => props.activeFilters.map((f) => f.key).join('|'),
  () => {
    if (props.activeFilters.length <= collapseLimit.value) {
      filtersExpanded.value = false
    }
  }
)
</script>
