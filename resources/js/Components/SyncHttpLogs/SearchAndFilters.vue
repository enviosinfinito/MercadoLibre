<template>
  <ListPageToolbar
    v-model:search="clientSearch"
    v-model:show-filters="showFilters"
    search-mode="simple"
    search-placeholder="Buscar por ID, correlation, tipo, URL…"
    :active-filters="activeFilters"
    :has-active-filters="hasActiveFilters"
    :compact-primary-actions="true"
    filters-slide-title="Filtros de logs"
    :show-default-filters-footer="false"
    @search="() => {}"
    @clear-search="clientSearch = ''"
    @remove-filter="onRemoveFilter"
    @clear-filters="onClearAll"
    @clear-filters-panel="onClearPanel"
    @apply-filters="onApply"
    @open-filters="onOpenFilters"
  >
    <template #primary-actions>
      <div class="flex flex-wrap items-center gap-1.5">
        <button
          type="button"
          class="inline-flex h-8 items-center rounded-lg border px-2.5 font-mono text-[11px] font-medium transition"
          :class="
            hasBodySearch
              ? 'border-sky-300 bg-sky-50 text-sky-800'
              : 'border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50'
          "
          @click="showAdvanced = true"
        >
          Avanzada
        </button>
        <div
          class="inline-flex max-w-full flex-wrap gap-0.5 rounded-lg border border-neutral-200/80 bg-neutral-50/80 p-0.5"
          role="group"
          aria-label="Presets HTTP"
        >
          <button
            v-for="preset in httpPresets"
            :key="preset.id"
            type="button"
            class="inline-flex h-7 items-center rounded-md px-2 font-mono text-[11px] font-medium transition"
            :class="
              activePreset === preset.id
                ? 'bg-white text-neutral-900 shadow-sm'
                : 'text-neutral-500 hover:text-neutral-800'
            "
            @click="applyHttpPreset(preset.id)"
          >
            {{ preset.label }}
          </button>
        </div>
      </div>
    </template>

    <template #mobile-tools>
      <button
        type="button"
        class="list-toolbar-mobile-toolbar-labeled relative text-neutral-700"
        :class="hasBodySearch ? 'text-sky-700' : ''"
        title="Búsqueda avanzada"
        @click="showAdvanced = true"
      >
        <span class="text-[10px] font-mono">Adv</span>
      </button>
      <button
        v-for="preset in httpPresets"
        :key="`m-${preset.id}`"
        type="button"
        class="list-toolbar-mobile-toolbar-labeled font-mono text-[10px]"
        :class="activePreset === preset.id ? 'bg-white text-neutral-900' : 'text-neutral-600'"
        @click="applyHttpPreset(preset.id)"
      >
        {{ preset.short }}
      </button>
    </template>

    <template #filters-panel>
      <div class="space-y-4">
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Dirección HTTP</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="panelFilters.http_direction ?? ''"
            @change="panelFilters.http_direction = $event.target.value"
          >
            <option value="">Todo HTTP</option>
            <option value="out">Salidas</option>
            <option value="in">Entradas / Webhooks</option>
          </select>
        </div>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Tipo webhook</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="panelFilters.topic ?? ''"
            @change="panelFilters.topic = $event.target.value"
          >
            <option value="">Todos</option>
            <option
              v-for="t in webhookTopics"
              :key="t.value"
              :value="t.value"
            >
              {{ t.label }} ({{ t.value }})
            </option>
          </select>
        </div>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Estado</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="panelFilters.status ?? ''"
            @change="panelFilters.status = $event.target.value"
          >
            <option value="">Todos</option>
            <option value="success">Éxito</option>
            <option value="error">Errores</option>
          </select>
        </div>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Conexión</label>
          <select
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
            :value="panelFilters.connection_id ?? ''"
            @change="panelFilters.connection_id = $event.target.value"
          >
            <option value="">Todas</option>
            <option
              v-for="c in connections"
              :key="c.id"
              :value="String(c.id)"
            >
              {{ connectionFilterLabel(c) }}
            </option>
          </select>
        </div>
        <FilterDateRangeFieldLinked
          :filter-object="panelFilters"
          start-key="from"
          end-key="to"
          label="Período"
        />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            class="rounded border-neutral-300"
            :checked="panelFilters.orphan === '1' || panelFilters.orphan === 1"
            @change="panelFilters.orphan = $event.target.checked ? '1' : ''"
          >
          Solo sin corrida (sync_run)
        </label>
        <div>
          <label class="mb-1.5 block text-xs font-medium text-neutral-500">Buscar en servidor (q)</label>
          <input
            type="text"
            class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 font-mono text-sm"
            :value="panelFilters.q ?? ''"
            placeholder="URL, endpoint, correlation, id…"
            @input="panelFilters.q = $event.target.value"
          >
        </div>
      </div>
    </template>

    <template #filters-footer>
      <div class="flex w-full items-center justify-between gap-2">
        <Button variant="ghost" size="sm" @click="onClearPanel">Limpiar</Button>
        <Button size="sm" @click="onApply">Aplicar</Button>
      </div>
    </template>
  </ListPageToolbar>

  <AdvancedBodySearchModal
    v-model:open="showAdvanced"
    :connections="connections"
    :initial="{
      body_search: filters.body_search ?? '',
      connection_id: filters.connection_id,
      from: filters.from ?? '',
      to: filters.to ?? '',
    }"
    @apply="onAdvancedApply"
  />
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import ListPageToolbar from '@/Components/ui/ListToolbar/ListPageToolbar.vue'
import Button from '@/Components/ui/Button.vue'
import FilterDateRangeFieldLinked from '@/Components/ui/FilterDateRangeFieldLinked.vue'
import AdvancedBodySearchModal from '@/Components/SyncHttpLogs/AdvancedBodySearchModal.vue'
import { useInertiaListFilters } from '@/composables/useInertiaListFilters'
import { createDefaultState } from '@/lib/filterEngine/normalize.js'
import { syncHttpLogsFilterSchema } from '@/lib/filterEngine/schemas/syncHttpLogs'
import { connectionFilterLabel } from '@/lib/connectionLabel'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  connections: { type: Array, default: () => [] },
  webhookTopics: { type: Array, default: () => [] },
  routeUrl: { type: String, default: () => route('sync-logs.index') },
  beforeApply: { type: Function, default: null },
  searchQuery: { type: String, default: '' },
})

const emit = defineEmits(['update:searchQuery', 'apply-filters', 'clear-filters', 'preset'])

const showFilters = ref(false)
const showAdvanced = ref(false)

const clientSearch = computed({
  get: () => props.searchQuery,
  set: (v) => emit('update:searchQuery', v ?? ''),
})

const httpPresets = [
  { id: 'all', label: 'Todo', short: 'Todo' },
  { id: 'outbound', label: 'Salidas', short: 'OUT' },
  { id: 'inbound', label: 'Entradas', short: 'IN' },
  { id: 'orphan', label: 'Sin corrida', short: 'Ø' },
]

const normalizedFilters = computed(() => ({
  q: props.filters.q ?? '',
  http_direction: props.filters.http_direction ?? '',
  status: props.filters.status ?? '',
  topic: props.filters.topic ?? '',
  connection_id: props.filters.connection_id != null && props.filters.connection_id !== ''
    ? String(props.filters.connection_id)
    : '',
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
  orphan: props.filters.orphan ? '1' : '',
  body_search: props.filters.body_search ?? '',
}))

const listFilters = useInertiaListFilters({
  schema: syncHttpLogsFilterSchema,
  routeUrl: props.routeUrl,
  initialFilters: normalizedFilters.value,
  getCatalogs: () => ({
    connections: props.connections,
    webhookTopics: props.webhookTopics,
  }),
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
  removeFilter,
  clearAllFilters,
  syncDraftFromState,
  commitDraft,
  clearDraft,
  watchExternalFilters,
  applyFilters,
} = listFilters

if (typeof watchExternalFilters === 'function') {
  watchExternalFilters(() => normalizedFilters.value)
}

const panelFilters = computed(() => {
  if (draft?.value) return draft.value
  return localFilters.value
})

const hasBodySearch = computed(() => !!String(props.filters.body_search || '').trim())

const activePreset = computed(() => {
  const dir = props.filters.http_direction ?? ''
  const orphan = props.filters.orphan === '1' || props.filters.orphan === 1
  if (orphan && !dir) return 'orphan'
  if (dir === 'out') return 'outbound'
  if (dir === 'in') return 'inbound'
  if (!dir && !orphan) return 'all'
  return null
})

function onRemoveFilter(filter) {
  removeFilter(filter)
}

function onClearAll() {
  const defaults = createDefaultState(syncHttpLogsFilterSchema)
  clearAllFilters(defaults)
  showFilters.value = false
}

function onClearPanel() {
  if (typeof clearDraft === 'function') {
    clearDraft()
    return
  }
  localFilters.value = createDefaultState(syncHttpLogsFilterSchema)
}

function onOpenFilters() {
  if (typeof syncDraftFromState === 'function') syncDraftFromState()
}

function onApply() {
  if (typeof commitDraft === 'function') {
    commitDraft()
  } else {
    applyFilters({ ...localFilters.value })
  }
  showFilters.value = false
}

watch(showFilters, (open) => {
  if (open) onOpenFilters()
})

function applyHttpPreset(presetId) {
  const next = {
    ...normalizedFilters.value,
    http_direction: '',
    orphan: '',
  }
  if (presetId === 'outbound') next.http_direction = 'out'
  if (presetId === 'inbound') next.http_direction = 'in'
  if (presetId === 'orphan') next.orphan = '1'

  if (typeof props.beforeApply === 'function') props.beforeApply(next)
  emit('preset', presetId)
  applyFilters(next)
}

function onAdvancedApply(payload) {
  const next = {
    ...normalizedFilters.value,
    body_search: payload.body_search,
    connection_id: payload.connection_id,
    from: payload.from,
    to: payload.to,
  }
  if (typeof props.beforeApply === 'function') props.beforeApply(next)
  applyFilters(next)
}

defineExpose({
  applyFilters,
  localFilters,
  clearAllFilters,
})
</script>
