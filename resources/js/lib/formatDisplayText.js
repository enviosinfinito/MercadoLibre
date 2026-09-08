const TITLE_PARTICLES = new Set(['de', 'del', 'la', 'las', 'los', 'y', 'e', 'da', 'das', 'do', 'dos'])

/**
 * @param {unknown} value
 * @returns {string}
 */
export function toTitleCase(value) {
  const raw = String(value ?? '').trim()
  if (!raw) return ''

  const words = raw.toLocaleLowerCase('es-MX').split(/\s+/).filter(Boolean)

  return words
    .map((word, index) => {
      if (index > 0 && TITLE_PARTICLES.has(word)) {
        return word
      }
      return word.charAt(0).toLocaleUpperCase('es-MX') + word.slice(1)
    })
    .join(' ')
}

/**
 * @param {unknown} value
 * @returns {string}
 */
export function toCodeCase(value) {
  const raw = String(value ?? '').trim()
  if (!raw) return ''
  return raw.toLocaleUpperCase('es-MX')
}

/**
 * @param {unknown} value
 * @param {'name' | 'title' | 'code' | 'label' | string | null | undefined} kind
 * @returns {string}
 */
export function formatByKind(value, kind) {
  const raw = String(value ?? '').trim()
  if (!raw) return ''

  switch (kind) {
    case 'name':
    case 'title':
      return toTitleCase(raw)
    case 'code':
      return toCodeCase(raw)
    case 'label':
    default:
      return raw
  }
}

/**
 * Soft fallback when backend only sends "CODE · NAME" summary string.
 *
 * @param {unknown} summary
 * @returns {{ primary: string, secondary: string | null, primary_kind: string, secondary_kind: string | null } | null}
 */
export function parseBuyerSummaryFallback(summary) {
  const raw = String(summary ?? '').trim()
  if (!raw) return null

  const sep = ' · '
  const idx = raw.indexOf(sep)
  if (idx === -1) {
    const looksLikeCode = /^[A-Z0-9._-]{3,}$/i.test(raw) && !/\s/.test(raw)
    return {
      primary: raw,
      secondary: null,
      primary_kind: looksLikeCode ? 'code' : 'name',
      secondary_kind: null,
    }
  }

  const left = raw.slice(0, idx).trim()
  const right = raw.slice(idx + sep.length).trim()
  if (!left || !right) {
    return {
      primary: raw,
      secondary: null,
      primary_kind: 'label',
      secondary_kind: null,
    }
  }

  // Legacy order: nickname · full name
  return {
    primary: right,
    secondary: left,
    primary_kind: 'name',
    secondary_kind: 'code',
  }
}
