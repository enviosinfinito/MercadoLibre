import { jsonFetch } from '@/lib/jsonFetch'

/**
 * @param {object} options
 * @param {(params: object) => string} options.buildUrl
 * @param {(data: unknown, url: string) => void} [options.onData]
 * @param {() => Record<string, string>} [options.headers]
 */
export function createFetchAdapter(options = {}) {
  const {
    buildUrl,
    onData = null,
    headers = () => ({}),
  } = options

  return {
    async apply(params, { signal } = {}) {
      const url = buildUrl(params)
      const data = await jsonFetch(url, {
        method: 'GET',
        headers: headers(),
        signal,
      })
      if (typeof onData === 'function') onData(data, url)
      return { data, url }
    },
  }
}
