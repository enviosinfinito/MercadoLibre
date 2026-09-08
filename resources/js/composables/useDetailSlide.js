import { ref } from 'vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'

/**
 * Carga por ID para slides de detalle (Tipo C).
 *
 * @template T
 * @param {{
 *   fetchUrl: (id: any) => string
 *   cache?: RequestCache | string
 *   onLoaded?: ((result: T) => void) | null
 *   onError?: ((error: unknown) => void) | null
 * }} options
 */
export function useDetailSlide(options) {
  const {
    fetchUrl,
    cache = 'default',
    onLoaded = null,
    onError = null,
  } = options

  const show = ref(false)
  const resourceId = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const data = ref(null)

  async function load(id) {
    if (typeof fetchUrl !== 'function') {
      throw new Error('fetchUrl es requerido')
    }

    loading.value = true
    error.value = null

    try {
      const url = fetchUrl(id)
      const result = await fetchSlidePayload(url, { cache })
      data.value = result
      if (typeof onLoaded === 'function') {
        onLoaded(result)
      }
      return result
    } catch (e) {
      const msg =
        e && typeof e === 'object' && 'message' in e && typeof e.message === 'string'
          ? e.message
          : 'No se pudo cargar.'
      error.value = msg
      if (typeof onError === 'function') {
        onError(e)
      }
      throw e
    } finally {
      loading.value = false
    }
  }

  async function open(id) {
    resourceId.value = id
    show.value = true
    await load(id)
  }

  async function retry() {
    if (resourceId.value == null) return
    await load(resourceId.value)
  }

  function close() {
    show.value = false
    resourceId.value = null
    data.value = null
    error.value = null
    loading.value = false
  }

  return {
    show,
    resourceId,
    loading,
    error,
    data,
    open,
    close,
    retry,
    load,
  }
}
