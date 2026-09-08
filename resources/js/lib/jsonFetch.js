/**
 * GET/POST JSON sin header X-Inertia (infinite scroll, AJAX filters, etc.).
 *
 * @param {string} url
 * @param {RequestInit & { signal?: AbortSignal }} [options]
 * @returns {Promise<any>}
 */
export async function jsonFetch(url, options = {}) {
  const { headers: extraHeaders, ...rest } = options

  const response = await fetch(url, {
    credentials: 'same-origin',
    ...rest,
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(extraHeaders || {}),
    },
  })

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    const message = data.message || data.error || `Error ${response.status}`
    const error = new Error(message)
    error.status = response.status
    error.data = data
    throw error
  }

  return data
}
