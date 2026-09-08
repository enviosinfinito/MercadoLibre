import { ref, computed, watch, unref } from 'vue'
import {
  createDefaultState,
  deserializeFromRaw,
  serializeForUrl,
  getActiveFilterPayload,
  normalizeForCompare,
  isEmptyFilterValue,
} from './normalize.js'
import { buildActiveChips, removeChipFromState } from './chips.js'
import { pruneCascadeSelections } from './cascade.js'
import { readFiltersFromLocation, replaceBrowserUrl } from './urlCodec.js'
import { useFilterQuery } from './useFilterQuery.js'
import { DEFAULT_EXCLUDE_KEYS } from './types.js'

/**
 * @param {object} options
 * @param {import('./types.js').FilterSchema} options.schema
 * @param {object} [options.codec] - URL codec (readFromLocation, serialize, buildUrl)
 * @param {{ apply: Function }} [options.transport]
 * @param {() => object} [options.getCatalogs]
 * @param {Record<string, number>} [options.debounceByKey]
 * @param {number} [options.defaultDebounceMs]
 * @param {object} [options.cascadeRules]
 * @param {boolean} [options.syncUrlOnApply]
 * @param {() => object} [options.watchExternal]
 */
export function useFilterEngine(options = {}) {
  const {
    schema,
    codec = null,
    transport = null,
    getCatalogs = () => ({}),
    debounceByKey = {},
    defaultDebounceMs = 350,
    cascadeRules = null,
    syncUrlOnApply = true,
    watchExternal = null,
  } = options

  const state = ref(createDefaultState(schema))
  const draft = ref(createDefaultState(schema))

  const query = useFilterQuery({
    onSuccess: options.onFetchSuccess,
    onError: options.onFetchError,
  })

  let debounceTimers = {}

  const filterContext = computed(() => ({
    catalogs: unref(getCatalogs()) ?? {},
    permissions: options.permissions ?? {},
    state: state.value,
  }))

  const activeChips = computed(() =>
    buildActiveChips(schema, state.value, filterContext.value)
  )

  const hasActiveFilters = computed(() =>
    activeChips.value.length > 0 ||
    Object.entries(state.value).some(([key, value]) => {
      const exclude = new Set([...DEFAULT_EXCLUDE_KEYS, ...(schema.excludeKeys || [])])
      if (exclude.has(key)) return false
      return !isEmptyFilterValue(value)
    })
  )

  function syncFromUrl() {
    const raw = readFiltersFromLocation(codec)
    state.value = deserializeFromRaw(schema, raw)
    draft.value = { ...state.value }
    return state.value
  }

  function syncDraftFromState() {
    draft.value = { ...state.value }
  }

  function patchState(patch, { cascadeFrom = null, apply = false } = {}) {
    let next = { ...state.value, ...patch }
    if (cascadeFrom && cascadeRules) {
      next = pruneCascadeSelections(schema, next, cascadeFrom, cascadeRules)
    }
    state.value = next
    if (apply) {
      return applyFilters(next)
    }
    return next
  }

  function patchDraft(patch, { cascadeFrom = null } = {}) {
    let next = { ...draft.value, ...patch }
    if (cascadeFrom && cascadeRules) {
      next = pruneCascadeSelections(schema, next, cascadeFrom, cascadeRules)
    }
    draft.value = next
    return next
  }

  function serialize(stateObj = state.value) {
    const flat = codec?.serialize
      ? codec.serialize(stateObj, schema)
      : serializeForUrl(schema, stateObj)
    return flat
  }

  async function applyFilters(nextState = state.value, { useDraft = false } = {}) {
    if (useDraft) {
      state.value = { ...draft.value }
    } else if (nextState !== state.value) {
      state.value = { ...nextState }
    }

    const flat = serialize(state.value)
    const url = codec?.buildUrl ? codec.buildUrl(flat) : null

    if (syncUrlOnApply && url && !transport) {
      replaceBrowserUrl(url)
    }

    if (!transport) {
      return { flat, url, state: state.value }
    }

    const result = await query.execute(flat, async (signal) => {
      const response = await transport.apply(flat, { signal })
      if (syncUrlOnApply && url) {
        replaceBrowserUrl(response?.url ?? url)
      }
      return response
    })

    return { flat, url, state: state.value, result }
  }

  function debouncedApply(key, nextState = state.value) {
    const ms = debounceByKey[key] ?? defaultDebounceMs
    clearTimeout(debounceTimers[key])
    if (ms <= 0) {
      return applyFilters(nextState)
    }
    debounceTimers[key] = setTimeout(() => {
      applyFilters(nextState)
    }, ms)
  }

  function removeChip(chip) {
    const next = removeChipFromState(schema, state.value, chip)
    state.value = next
    draft.value = { ...next }
    return applyFilters(next)
  }

  function clearAll() {
    const defaults = createDefaultState(schema)
    state.value = defaults
    draft.value = { ...defaults }
    if (typeof options.onClear === 'function') options.onClear()
    return applyFilters(defaults)
  }

  function clearDraft() {
    draft.value = createDefaultState(schema)
  }

  function commitDraft() {
    return applyFilters(draft.value, { useDraft: true })
  }

  function getExportPayload() {
    const raw = readFiltersFromLocation(codec)
    const fromUrl = deserializeFromRaw(schema, raw)
    const merged = { ...state.value, ...fromUrl }
    return getActiveFilterPayload(schema, merged)
  }

  if (typeof watchExternal === 'function') {
    watch(watchExternal, (external) => {
      if (!external) return
      const merged = deserializeFromRaw(schema, { ...state.value, ...external })
      if (JSON.stringify(normalizeForCompare(merged)) !== JSON.stringify(normalizeForCompare(state.value))) {
        state.value = merged
        draft.value = { ...merged }
      }
    }, { deep: true })
  }

  syncFromUrl()

  return {
    state,
    draft,
    activeChips,
    hasActiveFilters,
    isLoading: query.isLoading,
    error: query.error,
    syncFromUrl,
    syncDraftFromState,
    patchState,
    patchDraft,
    applyFilters,
    debouncedApply,
    removeChip,
    clearAll,
    clearDraft,
    commitDraft,
    serialize,
    getExportPayload,
    abort: query.abort,
    resetQueryCache: query.resetCache,
  }
}
