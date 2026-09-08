/**
 * Build export filters from the current URL query (source of truth after filter apply).
 * @param {string[]} [excludeKeys]
 * @returns {Record<string, string>}
 */
export function exportFiltersFromUrl(excludeKeys = ['page', 'per_page', 'sort', 'direction']) {
  const params = new URLSearchParams(window.location.search)
  const out = {}
  for (const [key, value] of params.entries()) {
    if (excludeKeys.includes(key)) continue
    out[key] = value
  }
  return out
}
