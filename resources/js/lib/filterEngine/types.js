/** @typedef {'string' | 'boolean' | 'number' | 'date' | 'date-range' | 'multi-select' | 'autocomplete' | 'global-search'} FilterType */

/** @typedef {'per-item' | 'single' | 'hidden'} ChipStrategy */

/**
 * @typedef {object} FilterChipConfig
 * @property {ChipStrategy} [strategy]
 * @property {(value: unknown, ctx: FilterContext) => string|null|undefined} [resolveLabel]
 * @property {boolean} [hideWhenGlobalSearchActive]
 */

/**
 * @typedef {object} FilterCascadeConfig
 * @property {string[]} [prunes] - keys to prune when this filter changes
 */

/**
 * @typedef {object} FilterDefinition
 * @property {string} key
 * @property {FilterType} type
 * @property {string} label
 * @property {string|string[]} [urlKey] - URL param name(s); aliases supported
 * @property {unknown} default
 * @property {FilterChipConfig} [chip]
 * @property {FilterCascadeConfig} [cascade]
 * @property {number|null} [debounceMs]
 * @property {(ctx: FilterContext) => boolean} [visible]
 * @property {string[]} [excludeFromUrl]
 */

/**
 * @typedef {object} FilterSchema
 * @property {FilterDefinition[]} filters
 * @property {string[]} [excludeKeys] - keys ignored for active/chip detection (page, per_page)
 * @property {Record<string, unknown>} [defaults]
 */

/**
 * @typedef {object} FilterContext
 * @property {Record<string, unknown>} catalogs
 * @property {Record<string, unknown>} permissions
 * @property {Record<string, unknown>} state
 */

/**
 * @typedef {object} ActiveChip
 * @property {string} key - unique chip id (may be `mensajeria_12`)
 * @property {string} filterKey - schema key
 * @property {string} label
 * @property {string} value
 * @property {string} [title]
 * @property {unknown} [arrayValue] - for per-item chips
 */

export const DEFAULT_EXCLUDE_KEYS = ['page', 'per_page', 'sort', 'direction']

export const FILTER_SELECTIVITY = {
  HIGH: 3,
  MEDIUM: 2,
  LOW: 1,
}
