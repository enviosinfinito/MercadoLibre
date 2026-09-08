<template>
  <div v-if="visible" class="w-full min-w-0">
    <!-- Móvil -->
    <div class="h-full w-full min-w-0 md:hidden">
      <div
        v-if="mobileBulkSelectPrompt"
        class="flex w-full flex-col gap-2.5 rounded-lg border border-brand-primary/20 bg-brand-primary/[0.06] px-3 py-2.5"
      >
        <div class="flex w-full items-center justify-between gap-2">
          <span class="text-[11px] font-semibold leading-none text-brand-primary">
            <span class="tabular-nums">{{ selectedCount }}</span>
            <span class="font-medium text-neutral-600"> seleccionados en cargados</span>
          </span>
          <button
            type="button"
            class="list-toolbar-mobile-icon-btn"
            title="Quitar selección"
            aria-label="Quitar selección"
            @click="$emit('clear-selection')"
          >
            <X class="size-3.5" :stroke-width="2" />
          </button>
        </div>
        <p class="w-full text-[12px] leading-snug text-neutral-700">
          ¿Quieres seleccionar los
          <span class="font-bold tabular-nums text-brand-primary">{{ formatCount(totalCount) }}</span>
          {{ itemLabel }} que coinciden con este filtro?
        </p>
        <button
          type="button"
          class="w-full rounded-lg bg-brand-primary py-2.5 text-[12px] font-bold leading-none text-white touch-manipulation active:bg-brand-primary-hover"
          :disabled="loadingAllIds"
          @click="$emit('select-all-filtered')"
        >
          Seleccionar todos del filtro
        </button>
      </div>

      <div v-else class="flex h-full w-full min-w-0 flex-col justify-center gap-1">
        <div class="flex w-full min-w-0 items-center justify-between gap-1.5">
          <SelectionSumsInline
            v-if="showSums"
            class="min-w-0 flex-1"
            compact
            :sum-items="sumItems"
            :sum-layout-placeholders="sumLayoutPlaceholders"
            :loading-all-ids="loadingAllIds"
            @copy-sum="onCopySum"
          />
          <button
            type="button"
            class="list-toolbar-mobile-icon-btn shrink-0"
            title="Quitar selección"
            aria-label="Quitar selección"
            @click="$emit('clear-selection')"
          >
            <X class="size-3.5" :stroke-width="2" />
          </button>
        </div>
        <div class="flex w-full min-w-0 items-center justify-between gap-1.5">
          <template v-if="selectAllFiltered">
            <span class="min-w-0 truncate text-[11px] leading-none">
              <span class="font-semibold tabular-nums text-brand-primary">{{ formatCountCompact(totalCount) }}</span>
              <span class="font-medium text-neutral-600"> en filtro</span>
            </span>
          </template>
          <template v-else-if="selectedCount > 0">
            <span class="min-w-0 truncate text-[11px] leading-none">
              <span class="font-semibold tabular-nums text-brand-primary">{{ selectedCount }}</span>
              <span class="text-neutral-600"> seleccionados</span>
            </span>
            <button
              v-if="showSelectAllButton"
              type="button"
              class="shrink-0 text-[10px] font-semibold leading-none text-brand-primary touch-manipulation active:opacity-70"
              :disabled="loadingAllIds"
              :title="`Seleccionar los ${formatCount(totalCount)} ${itemLabel} del filtro`"
              @click="$emit('select-all-filtered')"
            >
              Todos ({{ formatCountCompact(totalCount) }})
            </button>
          </template>
          <template v-else>
            <label
              class="inline-flex min-w-0 flex-1 cursor-pointer select-none items-center gap-1.5 overflow-hidden"
              :title="selectionLabel"
            >
              <input
                :id="checkboxIdMobile"
                type="checkbox"
                class="size-3.5 shrink-0 rounded border-neutral-300 text-[var(--brand-primary)] focus:ring-[var(--brand-primary)]"
                :checked="selectAll"
                @change="$emit('update:selectAll', $event.target.checked)"
              >
              <span class="truncate text-[11px] font-semibold leading-none text-brand-primary">Todos</span>
            </label>
            <span
              v-if="!hideCount"
              class="ml-auto shrink-0 tabular-nums text-[10px] font-medium leading-none text-neutral-500"
              :title="`${Number(totalCount).toLocaleString()} ${itemLabel} en el filtro actual`"
            >
              {{ formatCountCompact(totalCount) }}
            </span>
          </template>
          <slot name="actions" />
        </div>
      </div>
    </div>

    <!-- Escritorio -->
    <div class="hidden w-full min-w-0 items-center gap-x-2.5 gap-y-1 md:flex">
      <template v-if="selectAllFiltered || selectedCount > 0">
        <span class="shrink-0 select-none text-[11px] leading-none text-neutral-500">
          <span class="tabular-nums font-medium text-neutral-700">{{ Number(activeDisplayCount).toLocaleString() }}</span>
          seleccionados
          <template v-if="selectAllFiltered">
            <span class="text-neutral-400"> · filtro</span>
          </template>
        </span>

        <SelectionSumsInline
          v-if="showSums"
          class="min-w-0 flex-1"
          :sum-items="sumItems"
          :sum-layout-placeholders="sumLayoutPlaceholders"
          :loading-all-ids="loadingAllIds"
          @copy-sum="onCopySum"
        />

        <button
          v-if="showSelectAllButton"
          type="button"
          class="shrink-0 select-none border-0 bg-transparent p-0 text-[11px] font-medium leading-none text-neutral-500 transition-colors hover:text-brand-primary disabled:opacity-50"
          :disabled="loadingAllIds"
          @click="$emit('select-all-filtered')"
        >
          Todos ({{ Number(totalCount).toLocaleString() }})
        </button>

        <slot name="actions" />

        <button
          type="button"
          class="ml-auto inline-flex size-6 shrink-0 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-neutral-600"
          title="Quitar selección"
          aria-label="Quitar selección"
          @click="$emit('clear-selection')"
        >
          <X class="size-3.5" :stroke-width="2" />
        </button>
      </template>
      <template v-else>
        <label class="inline-flex cursor-pointer select-none items-center gap-2">
          <input
            :id="checkboxIdDesktop"
            type="checkbox"
            class="size-3.5 shrink-0 rounded border-neutral-300 text-[var(--brand-primary)] focus:ring-[var(--brand-primary)]"
            :checked="selectAll"
            @change="$emit('update:selectAll', $event.target.checked)"
          >
          <span class="list-toolbar-label">{{ selectionLabel }}</span>
        </label>
        <template v-if="!hideCount">
          <span class="select-none text-neutral-300" aria-hidden="true">·</span>
          <span class="list-toolbar-meta select-none">
            <span>{{ Number(totalCount).toLocaleString() }}</span>
            <span class="text-neutral-500"> {{ itemLabel }}</span>
          </span>
        </template>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { X } from 'lucide-vue-next'
import SelectionSumsInline from './SelectionSumsInline.vue'

const props = defineProps({
  visible: { type: Boolean, default: true },
  totalCount: { type: Number, default: 0 },
  selectedCount: { type: Number, default: 0 },
  selectAll: { type: Boolean, default: false },
  selectAllFiltered: { type: Boolean, default: false },
  mobileBulkSelectPrompt: { type: Boolean, default: false },
  selectionLabel: { type: String, default: 'Seleccionar cargados' },
  itemLabel: { type: String, default: 'elementos' },
  hideCount: { type: Boolean, default: false },
  checkboxIdMobile: { type: String, default: 'selectAllMobile' },
  checkboxIdDesktop: { type: String, default: 'selectAllDesktop' },
  sumItems: { type: Array, default: () => [] },
  sumLayoutPlaceholders: { type: Array, default: () => [] },
  loadingAllIds: { type: Boolean, default: false },
  showSelectAllFiltered: { type: Boolean, default: undefined },
  showSums: { type: Boolean, default: false },
  formatCount: {
    type: Function,
    default: (n) => Number(n ?? 0).toLocaleString(),
  },
  formatCountCompact: {
    type: Function,
    default: (n) => {
      const num = Number(n ?? 0)
      if (num >= 1000) {
        return `${(num / 1000).toFixed(num >= 10000 ? 0 : 1)}k`
      }
      return num.toLocaleString()
    },
  },
})

const emit = defineEmits(['update:selectAll', 'select-all-filtered', 'clear-selection', 'copy-sum'])

const showSelectAllButton = computed(() => {
  if (props.selectAllFiltered) return false
  if (props.showSelectAllFiltered !== undefined) return props.showSelectAllFiltered
  return props.selectedCount > 0 && props.totalCount > props.selectedCount
})

const activeDisplayCount = computed(() =>
  props.selectAllFiltered ? props.totalCount : props.selectedCount,
)

async function onCopySum(item) {
  emit('copy-sum', item)
  const text = item?.formatted
  if (!text || typeof navigator === 'undefined' || !navigator.clipboard) return
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    // ignore
  }
}
</script>
