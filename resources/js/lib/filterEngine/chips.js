import { isEmptyFilterValue } from './normalize.js'
import { DEFAULT_EXCLUDE_KEYS } from './types.js'

/**
 * @param {import('./types.js').FilterSchema} schema
 * @param {Record<string, unknown>} state
 * @param {import('./types.js').FilterContext} [ctx]
 * @returns {import('./types.js').ActiveChip[]}
 */
export function buildActiveChips(schema, state, ctx = { catalogs: {}, permissions: {}, state }) {
  const chips = []
  const exclude = new Set([...DEFAULT_EXCLUDE_KEYS, ...(schema.excludeKeys || [])])
  const context = { ...ctx, state }

  for (const def of schema.filters || []) {
    if (exclude.has(def.key)) continue
    const value = state[def.key]
    if (isEmptyFilterValue(value)) continue
    if (def.chip?.hideWhenGlobalSearchActive && def.key === 'multiple_search_mode') {
      const gs = state.global_search
      if (gs != null && String(gs).trim() !== '') continue
    }
    if (def.visible && !def.visible(context)) continue

    const strategy = def.chip?.strategy ?? (def.type === 'multi-select' ? 'per-item' : 'single')

    if (strategy === 'hidden') continue

    if (strategy === 'per-item' && Array.isArray(value)) {
      for (const item of value) {
        const display = def.chip?.resolveLabel
          ? def.chip.resolveLabel(item, context)
          : String(item)
        if (display == null || display === '') continue
        chips.push({
          key: `${def.key}_${item}`,
          filterKey: def.key,
          label: def.chip?.resolveLabel ? '' : def.label,
          value: display,
          title: def.label ? `${def.label}: ${display}` : display,
          arrayValue: item,
        })
      }
      continue
    }

    if (def.key === 'global_search' && value) {
      chips.push(...buildGlobalSearchChips(state, def))
      continue
    }

    const display = def.chip?.resolveLabel
      ? def.chip.resolveLabel(value, context)
      : formatDefaultDisplay(def, value)

    if (display == null || display === '') continue

    chips.push({
      key: def.key,
      filterKey: def.key,
      label: def.label,
      value: display,
      title: `${def.label}: ${display}`,
    })
  }

  return chips
}

function buildGlobalSearchChips(state, def) {
  const raw = String(state.global_search ?? '').trim()
  if (!raw) return []
  const terms = [...new Set(raw.split(',').map((t) => t.trim()).filter(Boolean))]
  const orMode = state.multiple_search_mode === true || state.multiple_search_mode === '1'
  const n = terms.length
  let core
  if (n === 1 && terms[0].length <= 28) core = terms[0]
  else if (n === 1) core = '1'
  else core = String(n)
  const displayValue = orMode ? `${core} · O` : core
  const modeTxt = orMode ? 'Cualquiera (O)' : 'Todas (Y)'
  const title =
    n === 1
      ? `Búsqueda: ${terms[0]} · ${modeTxt}`
      : `Búsqueda: ${n} términos · ${modeTxt}`

  return [{
    key: 'global_search',
    filterKey: 'global_search',
    label: '',
    value: displayValue,
    title,
  }]
}

function formatDefaultDisplay(def, value) {
  if (def.type === 'boolean' && value === true) return 'Sí'
  if (Array.isArray(value)) {
    return value.length > 1 ? `${value.length} seleccionados` : String(value[0] ?? '')
  }
  return String(value)
}

/**
 * Remove a chip from state; returns new state object.
 * @param {import('./types.js').FilterSchema} schema
 * @param {Record<string, unknown>} state
 * @param {import('./types.js').ActiveChip|string} chipOrKey
 */
export function removeChipFromState(schema, state, chipOrKey) {
  const chip = typeof chipOrKey === 'string'
    ? { key: chipOrKey, filterKey: chipOrKey }
    : chipOrKey

  const next = { ...state }
  const filterKey = chip.filterKey ?? chip.key

  if (chip.arrayValue !== undefined && Array.isArray(next[filterKey])) {
    next[filterKey] = next[filterKey].filter(
      (item) => String(item) !== String(chip.arrayValue)
    )
    return next
  }

  const def = (schema.filters || []).find((d) => d.key === filterKey)
  if (!def) {
    next[filterKey] = ''
    return next
  }

  if (filterKey === 'global_search') {
    next.global_search = ''
    next.multiple_search_mode = false
    return next
  }

  if (def.type === 'multi-select' || Array.isArray(next[filterKey])) {
    next[filterKey] = []
  } else if (def.type === 'boolean') {
    next[filterKey] = false
  } else {
    next[filterKey] = cloneDefault(def.default)
  }

  return next
}

function cloneDefault(value) {
  if (Array.isArray(value)) return []
  if (typeof value === 'boolean') return false
  if (typeof value === 'number') return value
  return ''
}
