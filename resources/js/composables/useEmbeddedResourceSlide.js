import { ref, computed } from 'vue'
import {
  fetchSlidePayload,
  refreshSlidePayload,
  resolveSlidePayloadUrl,
} from '@/lib/fetchSlidePayload'
import { peekSlidePayloadCache } from '@/lib/slidePayloadCache'
import {
  EMBEDDED_RESOURCES,
  getEmbeddedResource,
  resolveEmbeddedTitle,
} from '@/lib/embeddedResourceRegistry'

/**
 * Estado y carga para slides de recursos embebidos (Tipo A).
 */
export function useEmbeddedResourceSlide(options = {}) {
  const {
    allowedResources = null,
    context = 'embedded',
    onSaved = null,
    onClose = null,
  } = options

  const show = ref(false)
  const kind = ref(null)
  const resourceId = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const payload = ref(null)

  const closeOnSave = computed(() => context === 'own-index')

  const title = computed(() => {
    if (!kind.value) return 'Detalle'
    return resolveEmbeddedTitle(kind.value, payload.value)
  })

  const definition = computed(() => getEmbeddedResource(kind.value))

  function isAllowed(resource) {
    if (!allowedResources || !Array.isArray(allowedResources)) return true
    return allowedResources.includes(resource)
  }

  function resolvePayloadUrl(resource, id) {
    const def = getEmbeddedResource(resource)
    if (!def) return null
    return resolveSlidePayloadUrl(def.payloadRoute, id, def.payloadFallback)
  }

  async function loadPayload(resource, id, { forceRefresh = false } = {}) {
    const def = getEmbeddedResource(resource)
    if (!def) {
      throw new Error(`Recurso no soportado: ${resource}`)
    }

    const url = resolvePayloadUrl(resource, id)
    if (!url) {
      throw new Error('No se pudo resolver la URL del recurso')
    }

    if (forceRefresh) {
      return refreshSlidePayload(url)
    }

    return fetchSlidePayload(url)
  }

  async function open({ resource, id, initialPayload = null }) {
    if (!resource || !isAllowed(resource)) {
      return
    }

    const nid = id != null ? Number(id) : null
    if (nid != null && Number.isNaN(nid)) {
      return
    }

    kind.value = resource
    resourceId.value = nid
    payload.value = initialPayload
    error.value = null
    show.value = true

    if (initialPayload) {
      loading.value = false
      return
    }

    if (nid == null) {
      loading.value = false
      return
    }

    const def = getEmbeddedResource(resource)
    const url = resolvePayloadUrl(resource, nid)
    const cached = url ? peekSlidePayloadCache(url) : null

    if (cached) {
      payload.value = cached
      loading.value = false

      if (def && !def.cachePayload) {
        loadPayload(resource, nid, { forceRefresh: true })
          .then((fresh) => {
            if (kind.value === resource && resourceId.value === nid) {
              payload.value = fresh
            }
          })
          .catch(() => {})
      }
      return
    }

    loading.value = true
    try {
      payload.value = await loadPayload(resource, nid)
    } catch (e) {
      error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar el recurso.'
    } finally {
      loading.value = false
    }
  }

  function prefetch() {
    return Promise.resolve()
  }

  async function retry() {
    if (!kind.value || resourceId.value == null) return
    error.value = null
    loading.value = true
    try {
      payload.value = await loadPayload(kind.value, resourceId.value, { forceRefresh: true })
    } catch (e) {
      error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar el recurso.'
    } finally {
      loading.value = false
    }
  }

  function close() {
    show.value = false
    kind.value = null
    resourceId.value = null
    payload.value = null
    error.value = null
    loading.value = false
    if (typeof onClose === 'function') {
      onClose()
    }
  }

  function handleSaved(data) {
    if (typeof onSaved === 'function') {
      onSaved({ kind: kind.value, id: resourceId.value, data })
    }

    if (closeOnSave.value) {
      close()
    }
  }

  function mapEditorProps() {
    const def = definition.value
    if (!def || !def.mapProps) return {}
    return def.mapProps(payload.value ?? {}, { closeOnSave: closeOnSave.value })
  }

  return {
    show,
    kind,
    resourceId,
    loading,
    error,
    payload,
    title,
    definition,
    closeOnSave,
    context,
    open,
    close,
    retry,
    prefetch,
    handleSaved,
    mapEditorProps,
    EMBEDDED_RESOURCES,
  }
}
