/**
 * Render Mercado Libre post-sale / claim message text safely.
 * Supports ML HTML (<a>, <p>, <br>, <span>…) by keeping safe links and plain text.
 *
 * @param {unknown} raw
 * @returns {string}
 */
export function formatOrderMessageHtml(raw) {
  if (raw == null || raw === '') {
    return '—'
  }

  // Decode first so entity-encoded tags (&lt;p&gt;) become real markup we can strip.
  let text = decodeBasicEntities(String(raw))
  if (text.includes('&lt;') || text.includes('&amp;')) {
    text = decodeBasicEntities(text)
  }

  const anchors = []

  // Preserve safe anchors as tokens before stripping other tags.
  text = text.replace(/<a\b([^>]*)>([\s\S]*?)<\/a>/gi, (_full, attrs, label) => {
    const hrefMatch = String(attrs).match(/\bhref\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i)
    const href = (hrefMatch?.[1] ?? hrefMatch?.[2] ?? hrefMatch?.[3] ?? '').trim()
    if (!isSafeHttpUrl(href)) {
      return stripTags(label)
    }
    const token = `\u0000A${anchors.length}\u0000`
    anchors.push({ href, label: stripTags(label) || href })
    return token
  })

  // Turn common block/break tags into newlines so claim HTML stays readable.
  text = text
    .replace(/<\s*br\s*\/?>/gi, '\n')
    .replace(/<\/\s*p\s*>/gi, '\n')
    .replace(/<\s*p\b[^>]*>/gi, '')
    .replace(/<\/\s*div\s*>/gi, '\n')
    .replace(/<\s*div\b[^>]*>/gi, '')
    .replace(/<\/\s*li\s*>/gi, '\n')
    .replace(/<\s*li\b[^>]*>/gi, '• ')
    .replace(/<\/\s*(ul|ol)\s*>/gi, '\n')
    .replace(/<\s*(ul|ol)\b[^>]*>/gi, '')

  text = stripTags(text)
  text = decodeBasicEntities(text)
  text = text.replace(/\u00a0/g, ' ')
  text = text.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim()

  text = escapeHtml(text)
  text = text.replace(/\r\n|\r|\n/g, '<br>')

  text = text.replace(
    /(https?:\/\/[^\s<]+)/g,
    (url) => {
      const clean = url.replace(/[),.;!?]+$/u, '')
      const trailing = url.slice(clean.length)
      if (!isSafeHttpUrl(clean)) {
        return url
      }
      return `<a href="${escapeAttr(clean)}" target="_blank" rel="noopener noreferrer">${escapeHtml(clean)}</a>${trailing}`
    },
  )

  anchors.forEach((anchor, index) => {
    const html = `<a href="${escapeAttr(anchor.href)}" target="_blank" rel="noopener noreferrer">${escapeHtml(anchor.label)}</a>`
    text = text.split(`\u0000A${index}\u0000`).join(html)
  })

  return text
}

/**
 * @param {unknown} status
 * @returns {string}
 */
export function conversationStatusLabel(status) {
  const key = String(status ?? '').toLowerCase()
  return (
    {
      active: 'Activa',
      blocked: 'Bloqueada',
      closed: 'Cerrada',
      disabled: 'Deshabilitada',
    }[key] ?? (status ? String(status) : '')
  )
}

/**
 * @param {unknown} status
 * @returns {'success' | 'danger' | 'secondary' | 'warning'}
 */
export function conversationStatusVariant(status) {
  const key = String(status ?? '').toLowerCase()
  if (key === 'active') return 'success'
  if (key === 'blocked' || key === 'disabled') return 'danger'
  if (key === 'closed') return 'secondary'
  return 'warning'
}

/**
 * @param {unknown} status
 * @returns {string}
 */
export function claimStatusLabel(status) {
  const key = String(status ?? '').toLowerCase()
  return (
    {
      opened: 'Abierto',
      closed: 'Cerrado',
    }[key] ?? (status ? String(status) : '—')
  )
}

/**
 * @param {unknown} stage
 * @returns {string}
 */
export function claimStageLabel(stage) {
  const key = String(stage ?? '').toLowerCase()
  return (
    {
      claim: 'Reclamo',
      dispute: 'Mediación',
      recontact: 'Recontacto',
      stale: 'Inactivo',
      none: '—',
    }[key] ?? (stage ? String(stage) : '—')
  )
}

/**
 * Prefijo del banner de reclamo/mediación según stage + status.
 *
 * @param {{ stage?: unknown, status?: unknown } | null | undefined} claim
 * @returns {string}
 */
export function claimBannerPrefix(claim) {
  if (!claim) return ''
  const stage = String(claim.stage ?? '').toLowerCase()
  const status = String(claim.status ?? '').toLowerCase()
  if (stage === 'dispute' || stage === 'recontact') {
    return status === 'opened' ? 'Mediación iniciada' : 'Mediación'
  }
  return status === 'opened' ? 'Reclamo abierto' : 'Reclamo'
}

/**
 * Label legible del outcome de resolución de ML (`meta.resolution.reason`).
 *
 * @param {unknown} reason
 * @returns {string | null}
 */
export function claimResolutionLabel(reason) {
  if (reason == null || reason === '') return null
  const key = String(reason).toLowerCase()
  const labels = {
    item_returned: 'Producto devuelto',
    payment_refunded: 'Reembolso al comprador',
    partial_refunded: 'Reembolso parcial',
    opened_claim_by_mistake: 'Reclamo por error',
    not_delivered: 'No entregado',
    already_shipped: 'Ya enviado',
    found_missing_parts: 'Partes encontradas',
    no_bpp: 'Sin cobertura ML',
    coverage_decision: 'Decisión de cobertura',
  }
  if (labels[key]) return labels[key]
  return String(reason).replace(/_/g, ' ')
}

function isSafeHttpUrl(href) {
  try {
    const url = new URL(href)
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}

function stripTags(value) {
  return String(value ?? '').replace(/<[^>]*>/g, '')
}

function decodeBasicEntities(value) {
  return String(value)
    .replaceAll('&nbsp;', ' ')
    .replaceAll('&amp;', '&')
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll('&#39;', "'")
    .replaceAll('&apos;', "'")
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}

function escapeAttr(value) {
  return escapeHtml(value)
}
