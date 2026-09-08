/**
 * Generic query-string URL codec for filter state.
 */

export function parseQueryString(search = '') {
  if (typeof window !== 'undefined' && search === '' && !search.startsWith('?')) {
    search = window.location.search
  }
  const params = new URLSearchParams(search.startsWith('?') ? search : `?${search}`)
  const out = {}
  for (const [key, value] of params.entries()) {
    if (key.endsWith('[]')) {
      const k = key.slice(0, -2)
      if (!out[k]) out[k] = []
      out[k].push(value)
    } else if (out[key] !== undefined) {
      if (!Array.isArray(out[key])) out[key] = [out[key]]
      out[key].push(value)
    } else {
      out[key] = value
    }
  }
  return out
}

export function buildQueryString(flat, options = {}) {
  const { skipKeys = ['page'], arraySuffix = true } = options
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(flat || {})) {
    if (skipKeys.includes(k)) continue
    if (v === '' || v == null) continue
    if (Array.isArray(v)) {
      if (v.length === 0) continue
      v.forEach((item) => q.append(arraySuffix ? `${k}[]` : k, String(item)))
    } else if (typeof v === 'boolean') {
      if (v) q.append(k, '1')
    } else {
      q.append(k, String(v))
    }
  }
  return q.toString()
}

/**
 * @param {string} pathname
 * @param {Record<string, unknown>} flat
 * @param {{ buildPath?: (flat: Record<string, unknown>) => string|null, page?: number|null }} [options]
 */
export function buildListUrl(pathname, flat, options = {}) {
  const { buildPath, page = null } = options
  if (typeof buildPath === 'function') {
    const tokenPath = buildPath(flat)
    if (tokenPath) {
      let path = tokenPath
      if (page != null && page > 1) {
        path += `?page=${encodeURIComponent(String(page))}`
      }
      return path
    }
  }
  const qs = buildQueryString(flat)
  if (page != null && page > 1) {
    const q = new URLSearchParams(qs)
    q.set('page', String(page))
    return `${pathname}?${q.toString()}`
  }
  return qs ? `${pathname}?${qs}` : pathname
}

export function replaceBrowserUrl(url) {
  if (typeof history !== 'undefined' && history.replaceState) {
    history.replaceState(null, '', url)
  }
}

export function readFiltersFromLocation(codec) {
  if (typeof window === 'undefined') return {}
  if (codec?.readFromLocation) {
    return codec.readFromLocation()
  }
  return parseQueryString(window.location.search)
}
