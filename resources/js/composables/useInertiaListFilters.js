import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useFilterEngine } from '@/lib/filterEngine/useFilterEngine.js'
import { createInertiaAdapter } from '@/lib/filterEngine/adapters/inertiaAdapter.js'
import { buildActiveChips } from '@/lib/filterEngine/chips.js'
import { createDefaultState, isEmptyFilterValue, serializeForUrl } from '@/lib/filterEngine/normalize.js'
import { DEFAULT_EXCLUDE_KEYS } from '@/lib/filterEngine/types.js'

/**
 * Composable compartido para filtros de listados (Inertia o emit local).
 * Preferir siempre `schema` en código nuevo.
 *
 * @param {object} options
 * @param {string} [options.routeUrl]
 * @param {object} [options.initialFilters]
 * @param {import('@/lib/filterEngine/types.js').FilterSchema} [options.schema]
 * @param {string[]} [options.excludeKeys]
 * @param {Record<string, string>} [options.filterLabels]
 * @param {Record<string, (value: unknown, filters: object) => string|null|undefined>} [options.filterResolvers]
 * @param {() => object} [options.getCatalogs]
 * @param {number} [options.debounceMs]
 * @param {(params: object) => void} [options.onApply] - hook antes del navigate (ej. resetAccumulation)
 * @param {() => void} [options.onClear]
 * @param {object} [options.routerOptions]
 */
export function useInertiaListFilters(options = {}) {
  const {
    routeUrl = null,
    initialFilters = {},
    schema = null,
    excludeKeys = DEFAULT_EXCLUDE_KEYS,
    filterLabels = {},
    filterResolvers = {},
    getCatalogs = () => ({}),
    debounceMs = 350,
    onApply = null,
    onClear = null,
    routerOptions = {},
  } = options

  if (schema) {
    const legacySchema = {
      ...schema,
      filters: schema.filters.map((def) => ({
        ...def,
        chip: {
          ...(def.chip || {}),
          resolveLabel: def.chip?.resolveLabel
            ?? ((value, ctx) => {
              if (filterResolvers[def.key]) {
                return filterResolvers[def.key](value, ctx.state)
              }
              return undefined
            }),
        },
        label: def.label || filterLabels[def.key] || def.key,
      })),
      excludeKeys: [...new Set([...DEFAULT_EXCLUDE_KEYS, ...(schema.excludeKeys || []), ...excludeKeys])],
    }

    const inertiaTransport = routeUrl
      ? createInertiaAdapter({
          routeUrl,
          routerOptions,
          transformParams: (p) => ({ ...p, page: 1 }),
        })
      : null

    const transport = {
      async apply(params, ctx) {
        if (typeof onApply === 'function') onApply(params)
        if (inertiaTransport) {
          return inertiaTransport.apply(params, ctx)
        }
        return params
      },
    }

    const searchKeys = (legacySchema.filters || [])
      .filter((d) => d.debounceMs || d.key === 'search' || d.key === 'q' || d.type === 'global-search')
      .reduce((acc, d) => {
        acc[d.key] = d.debounceMs ?? debounceMs
        return acc
      }, {})

    const engine = useFilterEngine({
      schema: legacySchema,
      transport,
      getCatalogs,
      debounceByKey: {
        search: debounceMs,
        global_search: debounceMs,
        q: debounceMs,
        ...searchKeys,
      },
      defaultDebounceMs: 0,
      syncUrlOnApply: Boolean(routeUrl),
      onClear,
    })

    engine.state.value = { ...createDefaultState(legacySchema), ...initialFilters }
    engine.draft.value = { ...engine.state.value }

    const localFilters = engine.state

    return {
      engine,
      localFilters,
      draft: engine.draft,
      hasActiveFilters: engine.hasActiveFilters,
      activeFilters: engine.activeChips,
      activeFilterCount: computed(() => engine.activeChips.value.length),
      applyFilters: (params) => engine.applyFilters(params ?? localFilters.value),
      debouncedApply: (keyOrParams, maybeParams) => {
        if (typeof keyOrParams === 'string') {
          return engine.debouncedApply(keyOrParams, maybeParams ?? localFilters.value)
        }
        const searchKey = legacySchema.filters.find((d) => d.key === 'q' || d.key === 'search')?.key ?? 'q'
        return engine.debouncedApply(searchKey, keyOrParams ?? localFilters.value)
      },
      removeFilter: (chipOrKey, arrayKey = null, arrayValue = null) => {
        if (chipOrKey && typeof chipOrKey === 'object') {
          return engine.removeChip(chipOrKey)
        }
        const key = chipOrKey
        const chipKey = arrayKey && arrayValue != null ? `${arrayKey}_${arrayValue}` : key
        return engine.removeChip({
          key: chipKey,
          filterKey: arrayKey ?? key,
          arrayValue,
        })
      },
      clearAllFilters: (defaults = {}) => {
        engine.state.value = { ...createDefaultState(legacySchema), ...defaults }
        engine.draft.value = { ...engine.state.value }
        if (onClear) onClear()
        return engine.applyFilters()
      },
      clearSearchField: (field = 'q') => {
        engine.patchState({ [field]: '' }, { apply: true })
      },
      patchFilters: (patch, opts) => engine.patchState(patch, opts),
      patchDraft: (patch, opts) => engine.patchDraft(patch, opts),
      syncDraftFromState: () => engine.syncDraftFromState(),
      commitDraft: () => engine.commitDraft(),
      clearDraft: () => engine.clearDraft(),
      serialize: () => serializeForUrl(legacySchema, localFilters.value),
      syncFromProps: (next) => {
        if (next) {
          engine.state.value = { ...createDefaultState(legacySchema), ...next }
          engine.draft.value = { ...engine.state.value }
        }
      },
      buildActiveFilters: () => buildActiveChips(legacySchema, localFilters.value, {
        catalogs: getCatalogs(),
        permissions: {},
        state: localFilters.value,
      }),
      watchExternalFilters: (getFilters) => {
        watch(getFilters, (next) => {
          if (next) {
            engine.state.value = { ...createDefaultState(legacySchema), ...next }
          }
        }, { deep: true })
      },
    }
  }

  const localFilters = ref({ ...initialFilters })
  let debounceTimer = null

  function syncFromProps(nextFilters) {
    if (!nextFilters || typeof nextFilters !== 'object') return
    localFilters.value = { ...localFilters.value, ...nextFilters }
  }

  function hasActiveValue(key, value) {
    if (excludeKeys.includes(key)) return false
    return !isEmptyFilterValue(value)
  }

  const hasActiveFilters = computed(() =>
    Object.entries(localFilters.value).some(([key, value]) => hasActiveValue(key, value))
  )

  function resolveFilterDisplay(key, value, filters) {
    if (filterResolvers[key]) return filterResolvers[key](value, filters)
    if (typeof value === 'boolean' && value === true) return 'Sí'
    if (Array.isArray(value)) {
      return value.length > 1 ? `${value.length} seleccionados` : String(value[0] ?? '')
    }
    return String(value)
  }

  function buildActiveFilters(filtersObj = localFilters.value, labels = filterLabels) {
    const chips = []
    Object.entries(filtersObj).forEach(([key, value]) => {
      if (!hasActiveValue(key, value)) return
      const display = resolveFilterDisplay(key, value, filtersObj)
      if (display == null || display === '') return
      chips.push({
        key,
        filterKey: key,
        label: labels[key] || key,
        value: display,
        title: labels[key] ? `${labels[key]}: ${display}` : display,
      })
    })
    return chips
  }

  const activeFilters = computed(() => buildActiveFilters())
  const activeFilterCount = computed(() => activeFilters.value.length)

  function applyFilters(params = localFilters.value, extraRouterOptions = {}) {
    const payload = { ...params }
    if (typeof onApply === 'function') {
      onApply(payload)
    }
    if (!routeUrl) return
    router.get(routeUrl, payload, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      ...routerOptions,
      ...extraRouterOptions,
    })
  }

  function debouncedApply(params) {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
      applyFilters(params ?? localFilters.value)
    }, debounceMs)
  }

  function removeFilter(key, arrayKey = null, arrayValue = null) {
    if (key && typeof key === 'object') {
      const chip = key
      const next = { ...localFilters.value }
      if (chip.arrayValue != null && Array.isArray(next[chip.filterKey])) {
        next[chip.filterKey] = next[chip.filterKey].filter((item) => String(item) !== String(chip.arrayValue))
      } else if (typeof next[chip.filterKey] === 'boolean') {
        next[chip.filterKey] = false
      } else {
        next[chip.filterKey] = ''
      }
      localFilters.value = next
      applyFilters(next)
      return
    }
    const next = { ...localFilters.value }
    if (arrayKey && Array.isArray(next[arrayKey])) {
      next[arrayKey] = next[arrayKey].filter((item) => String(item) !== String(arrayValue))
    } else if (Array.isArray(next[key])) {
      next[key] = []
    } else if (typeof next[key] === 'boolean') {
      next[key] = false
    } else {
      next[key] = ''
    }
    localFilters.value = next
    applyFilters(next)
  }

  function clearAllFilters(defaults = {}) {
    localFilters.value = { ...defaults }
    if (typeof onClear === 'function') onClear()
    applyFilters(localFilters.value)
  }

  function clearSearchField(field = 'q') {
    localFilters.value = { ...localFilters.value, [field]: '' }
    applyFilters(localFilters.value)
  }

  function patchFilters(patch) {
    localFilters.value = { ...localFilters.value, ...patch }
  }

  function watchExternalFilters(getFilters) {
    watch(getFilters, (next) => syncFromProps(next), { deep: true })
  }

  return {
    localFilters,
    hasActiveFilters,
    activeFilters,
    activeFilterCount,
    applyFilters,
    debouncedApply,
    removeFilter,
    clearAllFilters,
    clearSearchField,
    patchFilters,
    syncFromProps,
    buildActiveFilters,
    watchExternalFilters,
  }
}
