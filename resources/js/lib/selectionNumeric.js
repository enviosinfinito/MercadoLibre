/**
 * Helpers de formato / lectura numérica para selección + sumas en listados.
 */

/**
 * @param {number} n
 * @param {{ currency?: string | null, style?: 'currency' | 'integer' | 'decimal' }} [meta]
 * @returns {string}
 */
export function formatSelectionNumeric(n, meta = {}) {
  const value = Number(n)
  if (!Number.isFinite(value)) {
    return '0'
  }

  const style = meta.style ?? (meta.currency ? 'currency' : 'integer')

  if (style === 'currency') {
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: meta.currency || 'MXN',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(value)
    } catch {
      return `${value.toFixed(2)} ${meta.currency ?? ''}`.trim()
    }
  }

  if (style === 'decimal') {
    return new Intl.NumberFormat(undefined, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(value)
  }

  return new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 0,
  }).format(value)
}

/**
 * @param {Record<string, unknown>} row
 * @param {string} key
 * @returns {number}
 */
export function getNumericField(row, key) {
  if (!row || typeof row !== 'object') return 0
  const raw = row[key]
  if (raw === null || raw === undefined || raw === '') return 0
  const n = typeof raw === 'string' ? Number(raw) : Number(raw)
  return Number.isFinite(n) ? n : 0
}

/**
 * @param {Array<{ key: string, label: string, fieldMeta?: unknown }>} columns
 * @param {Array<string|number>} selectedIds
 * @param {Array<Record<string, unknown>>} loadedRows
 * @param {(row: Record<string, unknown>, key: string) => number} getNumericValue
 * @param {(n: number, meta?: unknown) => string} formatNumeric
 */
export function sumSelectionClient(
  selectedIds,
  loadedRows,
  columns,
  getNumericValue = getNumericField,
  formatNumeric = formatSelectionNumeric,
) {
  const byId = new Map(loadedRows.map((r) => [String(r.id), r]))
  return columns.map((col) => {
    let sum = 0
    for (const id of selectedIds) {
      const row = byId.get(String(id))
      if (!row) continue
      const n = getNumericValue(row, col.key)
      if (Number.isFinite(n)) sum += n
    }
    return {
      key: col.key,
      label: col.label,
      sum,
      formatted: formatNumeric(sum, col.fieldMeta),
    }
  })
}
