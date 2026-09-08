import { ref, watch } from 'vue'
import {
  dateRangeFromFilterStrings,
  filterStringsFromDateRange,
  formatDateYmd,
  getDefaultDateRangePresets,
  isSameDateRange,
  resolvePresetRange,
} from '@/utils/filterDates'

/**
 * Sincroniza un ref de filtros (start/end strings YYYY-MM-DD) con dateRange Date[].
 */
export function useDateRangeFilter ({
  filters,
  startKey = 'start_date',
  endKey = 'end_date',
  immediate = true,
}) {
  const dateRange = ref(null)

  function syncDateRangeFromFilters () {
    const f = filters.value
    if (!f || typeof f !== 'object') {
      if (dateRange.value !== null) dateRange.value = null
      return
    }
    const next = dateRangeFromFilterStrings(f[startKey], f[endKey])
    if (!isSameDateRange(dateRange.value, next)) {
      dateRange.value = next
    }
  }

  function applyDateRangeToFilters (range) {
    const f = filters.value
    if (!f || typeof f !== 'object') return
    const { start, end } = filterStringsFromDateRange(range)
    if (f[startKey] === start && f[endKey] === end) return
    f[startKey] = start
    f[endKey] = end
  }

  function setDateRange (range) {
    if (range && range.length === 2 && range[0] && range[1]) {
      const start = range[0] instanceof Date ? range[0] : new Date(range[0])
      const end = range[1] instanceof Date ? range[1] : new Date(range[1])
      dateRange.value = [start, end]
      applyDateRangeToFilters(dateRange.value)
    }
  }

  function clearDateRange () {
    dateRange.value = null
    applyDateRangeToFilters(null)
  }

  function applyPreset (presetKey) {
    const range = resolvePresetRange(presetKey)
    if (range) {
      setDateRange(range)
    }
  }

  watch(dateRange, (range) => {
    applyDateRangeToFilters(range)
  }, { deep: true })

  if (immediate) {
    watch(filters, () => {
      syncDateRangeFromFilters()
    }, { deep: true, immediate: true })
  }

  return {
    dateRange,
    syncDateRangeFromFilters,
    setDateRange,
    clearDateRange,
    applyPreset,
    quickDateRanges: getDefaultDateRangePresets(),
    formatDateYmd,
  }
}

/**
 * Puente entre dos refs string (YYYY-MM-DD) y dateRange Date[].
 * Útil cuando las fechas no viven en un objeto de filtros.
 */
export function useDateRangeRefBridge (fromRef, toRef) {
  const dateRange = ref(null)

  function syncFromRefs () {
    const next = dateRangeFromFilterStrings(fromRef.value, toRef.value)
    if (!isSameDateRange(dateRange.value, next)) {
      dateRange.value = next
    }
  }

  function applyToRefs (range) {
    const { start, end } = filterStringsFromDateRange(range)
    if (fromRef.value === start && toRef.value === end) return
    fromRef.value = start
    toRef.value = end
  }

  function clearDateRange () {
    dateRange.value = null
    applyToRefs(null)
  }

  watch([fromRef, toRef], syncFromRefs, { immediate: true })
  watch(dateRange, (range) => {
    applyToRefs(range)
  }, { deep: true })

  return { dateRange, clearDateRange }
}
