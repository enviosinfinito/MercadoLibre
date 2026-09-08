const MS_PER_DAY = 86400000

export function parseFilterDate (str) {
  if (!str) return null
  const match = String(str).match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (match) {
    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  }
  const parsed = new Date(str)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

export function formatDateYmd (date) {
  if (!date) return ''
  const d = date instanceof Date ? date : new Date(date)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export function startOfToday () {
  const now = new Date()
  return new Date(now.getFullYear(), now.getMonth(), now.getDate())
}

export function resolvePresetRange (key) {
  const today = startOfToday()
  switch (key) {
    case 'today':
      return [today, today]
    case 'yesterday': {
      const yesterday = new Date(today.getTime() - MS_PER_DAY)
      return [yesterday, yesterday]
    }
    case 'last_7_days':
      return [new Date(today.getTime() - 6 * MS_PER_DAY), today]
    case 'last_30_days':
      return [new Date(today.getTime() - 29 * MS_PER_DAY), today]
    case 'this_month':
      return [new Date(today.getFullYear(), today.getMonth(), 1), today]
    default:
      return null
  }
}

export const DEFAULT_DATE_RANGE_PRESET_KEYS = [
  'today',
  'yesterday',
  'last_7_days',
  'last_30_days',
  'this_month',
]

export const DEFAULT_DATE_RANGE_PRESET_LABELS = {
  today: 'Hoy',
  yesterday: 'Ayer',
  last_7_days: '7 días',
  last_30_days: '30 días',
  this_month: 'Este mes',
}

/** Presets con rangos calculados al vuelo (para render en UI). */
export function getDefaultDateRangePresets () {
  return DEFAULT_DATE_RANGE_PRESET_KEYS.map((key) => ({
    key,
    label: DEFAULT_DATE_RANGE_PRESET_LABELS[key],
    range: resolvePresetRange(key),
  }))
}

export function dateRangeFromFilterStrings (startStr, endStr) {
  const start = parseFilterDate(startStr)
  const end = parseFilterDate(endStr)
  if (start && end) {
    return [start, end]
  }
  return null
}

export function filterStringsFromDateRange (range) {
  if (range && Array.isArray(range) && range[0] && range[1]) {
    return {
      start: formatDateYmd(range[0]),
      end: formatDateYmd(range[1]),
    }
  }
  return { start: '', end: '' }
}

export function isSameDateRange (a, b) {
  if (a == null && b == null) return true
  if (!a || !b || !Array.isArray(a) || !Array.isArray(b)) return false
  if (!a[0] || !a[1] || !b[0] || !b[1]) return false
  return formatDateYmd(a[0]) === formatDateYmd(b[0]) && formatDateYmd(a[1]) === formatDateYmd(b[1])
}

export function parseDateRangeToken (token) {
  if (!token) return null
  const parts = String(token).split(' to ')
  if (parts.length !== 2) return null
  const start = parseFilterDate(parts[0])
  const end = parseFilterDate(parts[1])
  if (start && end) {
    return [start, end]
  }
  return null
}

export function formatDateRangeToken (range) {
  if (!range || !Array.isArray(range) || !range[0] || !range[1]) return ''
  return `${formatDateYmd(range[0])} to ${formatDateYmd(range[1])}`
}
