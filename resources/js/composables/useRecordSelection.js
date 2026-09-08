import { computed, ref, unref, watch } from 'vue'
import { jsonFetch } from '@/lib/jsonFetch'
import {
  formatSelectionNumeric,
  getNumericField,
  sumSelectionClient,
} from '@/lib/selectionNumeric'

/**
 * Selección de filas + sumas (cliente / universo filtrado).
 *
 * @param {object} options
 * @param {import('vue').Ref|import('vue').ComputedRef|(() => any[])} options.loadedRows
 * @param {import('vue').Ref|import('vue').ComputedRef|(() => number)} options.totalCount
 * @param {import('vue').Ref|import('vue').ComputedRef|(() => Array<{key:string,label:string,fieldMeta?:unknown}>)} [options.summableColumns]
 * @param {() => Record<string, unknown>|URLSearchParams} options.currentQuery
 * @param {{ allIds: string|(() => string), filteredSums: string|(() => string) }} options.endpoints
 * @param {(n: number, meta?: unknown) => string} [options.formatNumeric]
 * @param {(row: Record<string, unknown>, key: string) => number} [options.getNumericValue]
 * @param {(msg: string, kind?: 'success'|'warning'|'error') => void} [options.notify]
 * @param {string} [options.itemLabel]
 * @param {boolean} [options.clearOnQueryChange]
 */
export function useRecordSelection(options) {
  const formatNumeric = options.formatNumeric ?? formatSelectionNumeric
  const getNumericValue = options.getNumericValue ?? getNumericField
  const notify = options.notify ?? (() => {})
  const clearOnQueryChange = options.clearOnQueryChange !== false
  const itemLabel = options.itemLabel ?? 'registros'

  const selectedIds = ref(/** @type {Array<string|number>} */ ([]))
  const allPagesSelected = ref(false)
  const serverSumsByKey = ref(/** @type {Record<string, number>|null} */ (null))
  const loadingAllIds = ref(false)
  const sumLayoutPlaceholders = ref(/** @type {string[]} */ ([]))
  const selectionNotice = ref(
    /** @type {{ kind: 'success'|'warning'|'error', text: string }|null} */ (null),
  )

  function resolveRef(source) {
    if (typeof source === 'function') return source()
    return unref(source)
  }

  const loadedRows = computed(() => {
    const rows = resolveRef(options.loadedRows)
    return Array.isArray(rows) ? rows : []
  })

  const totalCount = computed(() => Number(resolveRef(options.totalCount) ?? 0) || 0)

  const summableColumns = computed(() => {
    if (!options.summableColumns) return []
    const cols = resolveRef(options.summableColumns)
    return Array.isArray(cols) ? cols : []
  })

  function serializeQuery() {
    const q = options.currentQuery()
    if (q instanceof URLSearchParams) {
      const sorted = [...q.entries()].sort(([a], [b]) => a.localeCompare(b))
      return new URLSearchParams(sorted).toString()
    }
    const params = new URLSearchParams()
    Object.entries(q || {}).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') return
      if (Array.isArray(value)) {
        value.forEach((v) => params.append(key, String(v)))
        return
      }
      if (typeof value === 'boolean') {
        params.set(key, value ? '1' : '0')
        return
      }
      params.set(key, String(value))
    })
    const sorted = [...params.entries()].sort(([a], [b]) => a.localeCompare(b))
    return new URLSearchParams(sorted).toString()
  }

  function endpointUrl(which) {
    const ep = options.endpoints[which]
    const base = typeof ep === 'function' ? ep() : ep
    const qs = serializeQuery()
    if (!qs) return base
    return `${base}${base.includes('?') ? '&' : '?'}${qs}`
  }

  const hasSelection = computed(() => selectedIds.value.length > 0 || allPagesSelected.value)

  const activeSelectionCount = computed(() => {
    if (allPagesSelected.value) return totalCount.value
    return selectedIds.value.length
  })

  const loadedIds = computed(() => loadedRows.value.map((r) => r.id))

  const allLoadedSelected = computed(() => {
    if (allPagesSelected.value) return true
    const ids = loadedIds.value
    if (ids.length === 0) return false
    const set = new Set(selectedIds.value.map(String))
    return ids.every((id) => set.has(String(id)))
  })

  const someLoadedSelected = computed(() => {
    if (allPagesSelected.value) return true
    const set = new Set(selectedIds.value.map(String))
    const ids = loadedIds.value
    const count = ids.filter((id) => set.has(String(id))).length
    return count > 0 && count < ids.length
  })

  const showSelectAll = computed(
    () =>
      !allPagesSelected.value &&
      totalCount.value > selectedIds.value.length &&
      selectedIds.value.length > 0,
  )

  const selectedRowsSumByField = computed(() => {
    const columns = summableColumns.value
    if (columns.length === 0) return []

    if (allPagesSelected.value && serverSumsByKey.value) {
      return columns.map((col) => {
        const sum = Number(serverSumsByKey.value[col.key]) || 0
        return {
          key: col.key,
          label: col.label,
          sum,
          formatted: formatNumeric(sum, col.fieldMeta),
        }
      })
    }

    return sumSelectionClient(
      selectedIds.value,
      loadedRows.value,
      columns,
      getNumericValue,
      formatNumeric,
    )
  })

  function invalidateAllPagesMode() {
    if (!allPagesSelected.value && !serverSumsByKey.value) return
    allPagesSelected.value = false
    serverSumsByKey.value = null
  }

  function clearSelection() {
    selectedIds.value = []
    allPagesSelected.value = false
    serverSumsByKey.value = null
    loadingAllIds.value = false
    sumLayoutPlaceholders.value = []
    selectionNotice.value = null
  }

  function toggle(id) {
    invalidateAllPagesMode()
    const key = String(id)
    const idx = selectedIds.value.findIndex((x) => String(x) === key)
    if (idx >= 0) {
      selectedIds.value = selectedIds.value.filter((_, i) => i !== idx)
    } else {
      selectedIds.value = [...selectedIds.value, id]
    }
  }

  function isSelected(id) {
    if (allPagesSelected.value) return true
    return selectedIds.value.some((x) => String(x) === String(id))
  }

  function selectAllLoaded() {
    invalidateAllPagesMode()
    const set = new Set(selectedIds.value.map(String))
    const next = [...selectedIds.value]
    for (const row of loadedRows.value) {
      if (!set.has(String(row.id))) {
        next.push(row.id)
        set.add(String(row.id))
      }
    }
    selectedIds.value = next
  }

  function deselectLoaded() {
    invalidateAllPagesMode()
    const loaded = new Set(loadedIds.value.map(String))
    selectedIds.value = selectedIds.value.filter((id) => !loaded.has(String(id)))
  }

  function onHeaderCheckboxChange() {
    if (allPagesSelected.value) {
      clearSelection()
      return
    }
    if (allLoadedSelected.value) {
      deselectLoaded()
      return
    }
    selectAllLoaded()
  }

  async function selectAllInFilteredUniverse() {
    const columns = summableColumns.value
    sumLayoutPlaceholders.value = columns.map((c) => {
      const current = selectedRowsSumByField.value.find((s) => s.key === c.key)
      return current?.formatted || (c.fieldMeta?.currency ? '$999,999,999.00' : '999,999')
    })
    loadingAllIds.value = true
    serverSumsByKey.value = null

    try {
      const idsRes = await jsonFetch(endpointUrl('allIds'))
      const ids = idsRes.record_ids ?? idsRes.ids ?? []
      selectedIds.value = ids
      allPagesSelected.value = true

      if (columns.length > 0) {
        try {
          const sumsRes = await jsonFetch(endpointUrl('filteredSums'))
          serverSumsByKey.value = sumsRes.sums_by_key ?? null
        } catch (e) {
          serverSumsByKey.value = null
          const msg = e?.message || 'Totales no disponibles'
          selectionNotice.value = { kind: 'warning', text: msg }
          notify(msg, 'warning')
        }
      }

      const n = idsRes.total_count ?? ids.length
      const msg = `${Number(n).toLocaleString()} ${itemLabel} seleccionados`
      selectionNotice.value = { kind: 'success', text: msg }
      notify(msg, 'success')
    } catch (e) {
      const msg = e?.message || 'No se pudo seleccionar todo el filtro'
      selectionNotice.value = { kind: 'error', text: msg }
      notify(msg, 'error')
      selectAllLoaded()
    } finally {
      loadingAllIds.value = false
      sumLayoutPlaceholders.value = []
    }
  }

  /** Props para ListBulkSelectionToolbar / sticky bar */
  const bulkSelectionProps = computed(() => ({
    visible: true,
    totalCount: totalCount.value,
    selectedCount: activeSelectionCount.value,
    selectAll: allLoadedSelected.value,
    selectAllFiltered: allPagesSelected.value,
    selectionLabel: 'Seleccionar cargados',
    itemLabel,
    sumItems: selectedRowsSumByField.value,
    loadingAllIds: loadingAllIds.value,
    sumLayoutPlaceholders: sumLayoutPlaceholders.value,
    showSelectAllFiltered: showSelectAll.value,
    showSums: summableColumns.value.length > 0 && hasSelection.value,
  }))

  if (clearOnQueryChange) {
    watch(
      () => serializeQuery(),
      (next, prev) => {
        if (prev === undefined) return
        if (next !== prev) clearSelection()
      },
    )
  }

  return {
    selectedIds,
    allPagesSelected,
    serverSumsByKey,
    loadingAllIds,
    sumLayoutPlaceholders,
    selectionNotice,
    hasSelection,
    activeSelectionCount,
    allLoadedSelected,
    someLoadedSelected,
    showSelectAll,
    selectedRowsSumByField,
    summableColumns,
    bulkSelectionProps,
    toggle,
    isSelected,
    selectAllLoaded,
    deselectLoaded,
    onHeaderCheckboxChange,
    selectAllInFilteredUniverse,
    clearSelection,
  }
}
