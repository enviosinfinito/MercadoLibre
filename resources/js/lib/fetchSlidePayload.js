import {
  peekSlidePayloadCache,
  writeSlidePayloadCache,
} from '@/lib/slidePayloadCache'

/**
 * Resuelve URL de payload para slides (editor-payload, slide-payload, etc.).
 */
export function resolveSlidePayloadUrl(routeName, id, fallbackBuilder) {
  if (typeof window !== 'undefined' && window.route) {
    try {
      return window.route(routeName, id)
    } catch {
      // fallback below
    }
  }

  if (typeof fallbackBuilder === 'function') {
    return fallbackBuilder(id)
  }

  return null
}

/**
 * GET JSON para cargar un slide embebido.
 */
export async function fetchSlidePayload(url, { cache = 'default', forceRefresh = false } = {}) {
  if (!url) {
    throw new Error('URL de payload no válida')
  }

  if (!forceRefresh && cache !== 'no-store') {
    const cached = peekSlidePayloadCache(url)
    if (cached) {
      return cached
    }
  }

  const response = await fetch(url, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    credentials: 'same-origin',
    cache: cache === 'no-store' ? 'no-store' : 'default',
  })

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    const message = data.message || data.error || `Error ${response.status}`
    const error = new Error(message)
    error.status = response.status
    error.data = data
    throw error
  }

  if (cache !== 'no-store') {
    writeSlidePayloadCache(url, data)
  }

  return data
}

/**
 * Refresca payload en segundo plano (stale-while-revalidate).
 */
export async function refreshSlidePayload(url, { cache = 'default' } = {}) {
  return fetchSlidePayload(url, { cache, forceRefresh: true })
}
