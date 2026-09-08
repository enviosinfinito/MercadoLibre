import { DEFAULT_EXCLUDE_KEYS } from './types.js'

export function isEmptyFilterValue(value) {
  if (value === '' || value === null || value === undefined || value === false) {
    return true
  }
  if (Array.isArray(value)) {
    return value.length === 0
  }
  return false
}

export function toFilterBool(value) {
  return value === true || value === '1' || value === 1 || value === 'true'
}

export function toFilterArray(value) {
  if (Array.isArray(value)) {
    return value.filter((v) => v !== null && v !== '')
  }
  if (value == null || value === '') {
    return []
  }
  return [value]
}

export function toFilterNumber(value, fallback = 0) {
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}

/**
 * Build default state object from schema definitions.
 * @param {import('./types.js').FilterSchema} schema
 */
export function createDefaultState(schema) {
  const out = { ...(schema.defaults || {}) }
  for (const def of schema.filters || []) {
    if (!(def.key in out)) {
      out[def.key] = cloneDefault(def.default)
    }
  }
  return out
}

function cloneDefault(value) {
  if (Array.isArray(value)) return [...value]
  if (value != null && typeof value === 'object') return { ...value }
  return value
}

/**
 * Deserialize raw URL/query values into typed state using schema.
 * @param {import('./types.js').FilterSchema} schema
 * @param {Record<string, unknown>} raw
 */
export function deserializeFromRaw(schema, raw = {}) {
  const state = createDefaultState(schema)
  const defByKey = Object.fromEntries((schema.filters || []).map((d) => [d.key, d]))
  const defByUrlKey = buildUrlKeyIndex(schema.filters || [])

  for (const [rawKey, rawValue] of Object.entries(raw || {})) {
    const def = defByUrlKey[rawKey] || defByKey[rawKey]
    if (!def) continue
    state[def.key] = deserializeValue(def, rawValue)
  }

  applyAliasMerges(schema, raw, state)
  return state
}

function buildUrlKeyIndex(filters) {
  const index = {}
  for (const def of filters) {
    const keys = def.urlKey
      ? (Array.isArray(def.urlKey) ? def.urlKey : [def.urlKey])
      : [def.key]
    for (const k of keys) {
      index[k] = def
    }
    index[def.key] = def
  }
  return index
}

function deserializeValue(def, value) {
  switch (def.type) {
    case 'boolean':
      return toFilterBool(value)
    case 'multi-select':
      return toFilterArray(value).map((v) => {
        const n = Number(v)
        return Number.isFinite(n) && String(v).trim() !== '' && def.key !== 'tracking_status' && def.key !== 'moneda'
          ? n
          : v
      })
    case 'number':
      return toFilterNumber(value, def.default ?? 0)
    default:
      if (Array.isArray(value)) return toFilterArray(value)
      return value == null ? cloneDefault(def.default) : value
  }
}

function applyAliasMerges(schema, raw, state) {
  if (raw.account_id && !raw.account_ids && state.account_ids === '') {
    state.account_ids = raw.account_id
  }
  if (raw.start_date && !state.date_from) {
    state.date_from = raw.start_date
    if (!state.start_date) state.start_date = raw.start_date
  }
  if (raw.end_date && !state.date_to) {
    state.date_to = raw.end_date
    if (!state.end_date) state.end_date = raw.end_date
  }
  if (raw.q != null && String(raw.q).trim() !== '' && !state.global_search) {
    state.global_search = String(raw.q).trim()
  }
  if (raw.or != null && state.multiple_search_mode === false) {
    state.multiple_search_mode = toFilterBool(raw.or)
  }
}

/**
 * Serialize state to flat URL-ready object (non-empty values only).
 * @param {import('./types.js').FilterSchema} schema
 * @param {Record<string, unknown>} state
 * @param {{ includeEmpty?: boolean }} [options]
 */
export function serializeForUrl(schema, state, options = {}) {
  const out = {}
  for (const def of schema.filters || []) {
    if (def.excludeFromUrl) continue
    const value = state[def.key]
    if (!options.includeEmpty && isEmptyFilterValue(value)) continue
    const urlKeys = def.urlKey
      ? (Array.isArray(def.urlKey) ? def.urlKey : [def.urlKey])
      : [def.key]
    out[urlKeys[0]] = serializeValue(def, value)
  }
  return out
}

function serializeValue(def, value) {
  if (def.type === 'boolean') {
    return value ? '1' : '0'
  }
  return value
}

/**
 * Active non-empty filters for export/bulk (excludes pagination meta).
 */
export function getActiveFilterPayload(schema, state, extraExclude = []) {
  const exclude = new Set([...DEFAULT_EXCLUDE_KEYS, ...(schema.excludeKeys || []), ...extraExclude])
  const out = {}
  for (const [key, value] of Object.entries(state || {})) {
    if (exclude.has(key)) continue
    if (isEmptyFilterValue(value)) continue
    out[key] = value
  }
  return out
}

/**
 * Normalize state for comparison (sorted arrays, drop empty).
 */
export function normalizeForCompare(state) {
  const out = {}
  for (const [key, value] of Object.entries(state || {})) {
    if (isEmptyFilterValue(value)) continue
    if (Array.isArray(value)) {
      out[key] = [...value].map(String).sort()
    } else {
      out[key] = value
    }
  }
  return out
}

export function statesEqual(a, b) {
  return JSON.stringify(normalizeForCompare(a)) === JSON.stringify(normalizeForCompare(b))
}
