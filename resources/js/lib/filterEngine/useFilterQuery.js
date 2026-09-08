import { ref } from 'vue'

const DEFAULT_DEDUPE_TTL_MS = 2000

/**
 * Fetch layer with AbortController, sequence guard, and optional deduplication.
 */
export function useFilterQuery(options = {}) {
  const {
    dedupeTtlMs = DEFAULT_DEDUPE_TTL_MS,
    onSuccess = null,
    onError = null,
  } = options

  const isLoading = ref(false)
  const error = ref(null)

  let abortController = null
  let requestSeq = 0
  let lastSuccessHash = ''
  let lastSuccessAt = 0
  let lastSuccessResult = null

  function hashParams(params) {
    try {
      return JSON.stringify(params)
    } catch {
      return String(Date.now())
    }
  }

  function abort() {
    if (abortController) {
      abortController.abort()
      abortController = null
    }
  }

  /**
   * @param {object} params
   * @param {(signal: AbortSignal, seq: number) => Promise<unknown>} fetcher
   */
  async function execute(params, fetcher) {
    const hash = hashParams(params)
    const now = Date.now()
    if (
      hash === lastSuccessHash &&
      lastSuccessResult != null &&
      now - lastSuccessAt < dedupeTtlMs
    ) {
      return lastSuccessResult
    }

    abort()
    abortController = new AbortController()
    const seq = ++requestSeq
    const signal = abortController.signal

    isLoading.value = true
    error.value = null

    try {
      const result = await fetcher(signal, seq)
      if (seq !== requestSeq) {
        return null
      }
      lastSuccessHash = hash
      lastSuccessAt = Date.now()
      lastSuccessResult = result
      if (typeof onSuccess === 'function') onSuccess(result, params)
      return result
    } catch (err) {
      if (err?.name === 'AbortError') return null
      if (seq !== requestSeq) return null
      error.value = err
      if (typeof onError === 'function') onError(err, params)
      throw err
    } finally {
      if (seq === requestSeq) {
        isLoading.value = false
      }
    }
  }

  function resetCache() {
    lastSuccessHash = ''
    lastSuccessAt = 0
    lastSuccessResult = null
  }

  return {
    isLoading,
    error,
    execute,
    abort,
    resetCache,
  }
}
