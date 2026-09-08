/**
 * @module filterEngine
 * Schema-driven filters: state/draft, URL sync, chips, abortable fetch.
 */

export { DEFAULT_EXCLUDE_KEYS, FILTER_SELECTIVITY } from './types.js'

export {
  isEmptyFilterValue,
  toFilterBool,
  toFilterArray,
  createDefaultState,
  deserializeFromRaw,
  serializeForUrl,
  getActiveFilterPayload,
  normalizeForCompare,
  statesEqual,
} from './normalize.js'

export {
  parseQueryString,
  buildQueryString,
  buildListUrl,
  replaceBrowserUrl,
  readFiltersFromLocation,
} from './urlCodec.js'

export { buildActiveChips, removeChipFromState } from './chips.js'
export { pruneCascadeSelections } from './cascade.js'
export { useFilterQuery } from './useFilterQuery.js'
export { useFilterEngine } from './useFilterEngine.js'

export { createInertiaAdapter } from './adapters/inertiaAdapter.js'
export { createFetchAdapter } from './adapters/fetchAdapter.js'
export { createClientAdapter } from './adapters/clientAdapter.js'
