import { unref } from 'vue'
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl'

/**
 * Payload for ExportToolbarButton from useRecordSelection state.
 *
 * - Partial checkbox selection → selection_mode: ids
 * - "Select all filtered" (allPagesSelected) or empty → filter (no giant id lists)
 *
 * @param {object} options
 * @param {import('vue').Ref|Array<string|number>} options.selectedIds
 * @param {import('vue').Ref|boolean} options.allPagesSelected
 * @param {import('vue').Ref|number|null} [options.totalCount]
 */
export function buildExportSelectionPayload({ selectedIds, allPagesSelected, totalCount }) {
  const ids = [...(unref(selectedIds) ?? [])]
  const allFiltered = Boolean(unref(allPagesSelected))
  const total = unref(totalCount)

  if (allFiltered || ids.length === 0) {
    return {
      selectionMode: 'filter',
      ids: [],
      selectedCount: 0,
      filters: exportFiltersFromUrl(),
      filteredTotalHint: total ?? null,
    }
  }

  return {
    selectionMode: 'ids',
    ids,
    selectedCount: ids.length,
    filters: exportFiltersFromUrl(),
    filteredTotalHint: null,
  }
}
