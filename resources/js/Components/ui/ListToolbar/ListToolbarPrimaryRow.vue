<template>
  <div class="mb-2 overflow-hidden md:mb-3">
    <!-- Móvil -->
    <div class="space-y-3 md:hidden">
      <slot v-if="showSearch" name="search">
        <ListToolbarSimpleSearch
          v-if="searchMode === 'simple'"
          :model-value="search"
          :placeholder="searchPlaceholder"
          :visible-count="visibleCount"
          @update:model-value="$emit('update:search', $event)"
          @search="$emit('search', $event)"
          @clear="$emit('clear-search')"
        />
      </slot>

      <div
        v-if="$slots['mobile-primary-actions'] || $slots['primary-actions']"
        class="grid w-full min-w-0 grid-cols-2 gap-2"
        role="group"
        aria-label="Acciones principales"
      >
        <slot name="mobile-primary-actions">
          <slot name="primary-actions" />
        </slot>
      </div>

      <div
        v-if="showMobileTools"
        class="overflow-hidden rounded-xl border border-neutral-200/80 bg-white p-1.5 shadow-[0_1px_8px_rgba(0,0,0,0.04)]"
        role="group"
        :aria-label="mobileBulkSelectPrompt ? 'Selección masiva' : 'Herramientas y selección'"
      >
        <div v-if="mobileBulkSelectPrompt && showSelection" class="w-full min-w-0">
          <slot name="selection" />
        </div>
        <div v-else class="flex h-10 w-full items-stretch gap-1.5">
          <div class="flex min-w-0 flex-[3] items-stretch gap-px overflow-hidden rounded-lg border border-neutral-200/70 bg-neutral-50/60 p-0.5">
            <slot name="mobile-tools" />
            <button
              v-if="showFiltersButton"
              type="button"
              class="list-toolbar-mobile-toolbar-labeled relative text-neutral-700"
              title="Abrir filtros de búsqueda"
              @click="$emit('open-filters')"
            >
              <Filter class="h-3 w-3 text-neutral-500" aria-hidden="true" />
              <span>Filtros</span>
              <span
                v-if="filterCount > 0"
                class="absolute right-1 top-0.5 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-[var(--brand-primary)] px-0.5 text-[8px] font-bold leading-none text-white"
              >
                {{ filterCount }}
              </span>
            </button>
            <div v-if="$slots['more-actions-trigger']" class="relative min-w-0 flex-1">
              <slot name="more-actions-trigger" />
            </div>
          </div>
          <div
            v-if="showSelection"
            class="flex min-w-0 flex-[2] items-center overflow-hidden rounded-lg border border-neutral-200/70 bg-neutral-50/40 px-2"
          >
            <slot name="selection" />
          </div>
        </div>
      </div>
    </div>

    <!-- Escritorio: búsqueda flex-1 + acciones alineadas a la derecha -->
    <div class="hidden md:block">
      <div class="flex min-w-0 items-end gap-3">
        <div v-if="showSearch" class="min-w-0 flex-1">
          <slot name="search">
            <ListToolbarSimpleSearch
              v-if="searchMode === 'simple'"
              :model-value="search"
              :placeholder="searchPlaceholder"
              :visible-count="visibleCount"
              @update:model-value="$emit('update:search', $event)"
              @search="$emit('search', $event)"
              @clear="$emit('clear-search')"
            />
          </slot>
        </div>

        <div class="flex shrink-0 items-end gap-1.5">
          <div v-if="hasPrimaryActions" class="flex flex-col items-stretch gap-1">
            <div
              v-if="$slots['primary-actions-label']"
              class="px-0.5 text-center text-[11px] font-semibold leading-relaxed tracking-[0.06em] text-brand-primary"
            >
              <slot name="primary-actions-label" />
            </div>
            <ActionBar
              preset="toolbar"
              :full-width="false"
              compact
              :show-informative-pages="false"
              class="shrink-0 !border-0 !bg-transparent !p-0 !shadow-none"
            >
              <template #end>
                <div
                  class="flex h-10 min-h-10 shrink-0 items-stretch"
                  :class="compactPrimaryActions
                    ? ''
                    : 'min-w-0 overflow-hidden rounded-2xl border border-neutral-200/80 bg-white shadow-[0_2px_16px_rgba(0,0,0,0.05)]'"
                >
                  <slot name="primary-actions" />
                </div>
              </template>
            </ActionBar>
          </div>

          <ListToolbarToolsGroup
            v-if="showFiltersButton"
            :filter-count="filterCount"
            @open-filters="$emit('open-filters')"
          >
            <template v-if="$slots['more-actions']" #more-actions>
              <slot name="more-actions" />
            </template>
          </ListToolbarToolsGroup>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useSlots, computed } from 'vue'
import { Filter } from 'lucide-vue-next'
import { ActionBar } from '@/Components/ui/ActionBar'
import ListToolbarSimpleSearch from './ListToolbarSimpleSearch.vue'
import ListToolbarToolsGroup from './ListToolbarToolsGroup.vue'

const props = defineProps({
  searchMode: { type: String, default: 'simple' },
  search: { type: String, default: '' },
  searchPlaceholder: { type: String, default: 'Buscar…' },
  visibleCount: { type: [Number, String], default: null },
  filterCount: { type: Number, default: 0 },
  showSelection: { type: Boolean, default: false },
  showMobileTools: { type: Boolean, default: true },
  showSearch: { type: Boolean, default: true },
  mobileBulkSelectPrompt: { type: Boolean, default: false },
  compactPrimaryActions: { type: Boolean, default: true },
  showFiltersButton: { type: Boolean, default: true },
})

defineEmits(['update:search', 'search', 'clear-search', 'open-filters', 'clear-filters'])

const slots = useSlots()
const hasPrimaryActions = computed(() => Boolean(slots['primary-actions'] || slots['primary-action']))
</script>

