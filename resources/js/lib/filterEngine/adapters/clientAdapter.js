/**
 * Client-side filter adapter: no network; emits state for local computed filtering.
 * @param {(params: object) => void} onApply
 */
export function createClientAdapter(onApply) {
  return {
    async apply(params) {
      if (typeof onApply === 'function') onApply(params)
      return params
    },
  }
}
