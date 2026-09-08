import { ref, computed } from 'vue'
import { fetchSlidePayload, resolveSlidePayloadUrl } from '@/lib/fetchSlidePayload'
import { getIndexCrudSlide, resolveIndexCrudTitle } from '@/lib/indexCrudSlideRegistry'

/**
 * Estado para slides CRUD en propio listado (Tipo B).
 */
export function useIndexCrudSlide(options = {}) {
  const { kind: initialKind = null, onSaved = null, onClose = null } = options

  const kind = ref(initialKind)
  const show = ref(false)
  const record = ref(null)
  const payload = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const extraProps = ref({})

  const definition = computed(() => (kind.value ? getIndexCrudSlide(kind.value) : null))

  const title = computed(() => {
    if (!kind.value) return 'Editor'
    return resolveIndexCrudTitle(kind.value, record.value ?? payload.value)
  })

  async function loadFetchPayload(id) {
    const def = definition.value
    if (!def?.payloadRoute) return null
    const url = resolveSlidePayloadUrl(def.payloadRoute, id, def.payloadFallback)
    if (!url) return null
    return fetchSlidePayload(url)
  }

  async function open(nextRecord = null, ctx = {}) {
    if (!kind.value && !ctx.kind) {
      return
    }
    if (ctx.kind) {
      kind.value = ctx.kind
    }
    extraProps.value = ctx.extraProps ?? {}

    record.value = nextRecord
    payload.value = null
    error.value = null
    show.value = true

    const def = definition.value
    if (def?.loadMode === 'fetch' && nextRecord?.id) {
      loading.value = true
      try {
        payload.value = await loadFetchPayload(nextRecord.id)
      } catch (e) {
        error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar.'
      } finally {
        loading.value = false
      }
    }
  }

  async function retry() {
    const id = record.value?.id
    if (!id) return
    loading.value = true
    error.value = null
    try {
      payload.value = await loadFetchPayload(id)
    } catch (e) {
      error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar.'
    } finally {
      loading.value = false
    }
  }

  function close() {
    show.value = false
    record.value = null
    payload.value = null
    error.value = null
    loading.value = false
    if (typeof onClose === 'function') {
      onClose()
    }
  }

  function handleSaved(data) {
    if (typeof onSaved === 'function') {
      onSaved({ kind: kind.value, record: record.value, data })
    }
    close()
  }

  function mapEditorProps() {
    const def = definition.value
    if (!def?.mapProps) return {}
    const source = def.loadMode === 'fetch' ? payload.value : record.value
    return def.mapProps(source, { extraProps: extraProps.value })
  }

  function resolveComponent() {
    const def = definition.value
    if (!def) return null
    const isCreate = !record.value?.id
      && !record.value?.configuration?.id
      && !payload.value?.configuration?.id
      && !payload.value?.carrierAccount?.id
    if (isCreate && def.createComponent) {
      return def.createComponent
    }
    return def.component
  }

  function updateExtraProps(patch) {
    extraProps.value = { ...extraProps.value, ...patch }
  }

  return {
    kind,
    show,
    record,
    payload,
    loading,
    error,
    extraProps,
    title,
    definition,
    open,
    close,
    retry,
    handleSaved,
    mapEditorProps,
    resolveComponent,
    updateExtraProps,
  }
}
