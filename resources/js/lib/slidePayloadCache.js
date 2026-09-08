/** TTL por defecto para payloads de slide (ms). */
export const SLIDE_PAYLOAD_CACHE_TTL_MS = 120_000

const store = new Map()

/**
 * @param {string} url
 * @returns {object|null}
 */
export function peekSlidePayloadCache(url) {
  if (!url) return null
  const entry = store.get(url)
  if (!entry) return null
  if (Date.now() > entry.expiresAt) {
    store.delete(url)
    return null
  }
  return entry.data
}

/**
 * @param {string} url
 * @param {object} data
 * @param {number} [ttlMs]
 */
export function writeSlidePayloadCache(url, data, ttlMs = SLIDE_PAYLOAD_CACHE_TTL_MS) {
  if (!url || data == null) return
  store.set(url, {
    data,
    expiresAt: Date.now() + ttlMs,
  })
}

/**
 * Invalida entradas de caché (URL exacta o prefijo).
 * @param {string} urlOrPrefix
 */
export function invalidateSlidePayloadCache(urlOrPrefix) {
  if (!urlOrPrefix) return
  for (const key of store.keys()) {
    if (key === urlOrPrefix || key.startsWith(urlOrPrefix)) {
      store.delete(key)
    }
  }
}

export function clearSlidePayloadCache() {
  store.clear()
}
