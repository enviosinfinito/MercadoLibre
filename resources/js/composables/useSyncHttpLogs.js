/**
 * Helpers de presentación / filtrado cliente para Sync HTTP Logs (estética consola).
 */

export function getTypeTag(row) {
  const raw = row?.webhook_topic || row?.endpoint_group || row?.provider || 'http'
  const token = String(raw).split('.').pop() || raw
  return `[${String(token).toUpperCase()}]`
}

export function getTypeTagClass(row) {
  const key = String(row?.webhook_topic || row?.endpoint_group || '').toLowerCase()
  if (key.includes('error') || key.includes('claim')) return 'text-red-600'
  if (key.includes('order') || key.includes('booking')) return 'text-emerald-600'
  if (key.includes('quote') || key.includes('search') || key.includes('item')) return 'text-sky-600'
  if (key.includes('shipment') || key.includes('pickup')) return 'text-violet-600'
  if (key.includes('cancel') || key.includes('payment')) return 'text-amber-600'
  return 'text-neutral-500'
}

export function directionBadgeClass(direction) {
  if (direction === 'in') return 'bg-violet-100 text-violet-700'
  return 'bg-sky-100 text-sky-700'
}

export function directionLabel(direction) {
  return direction === 'in' ? 'IN' : 'OUT'
}

/** @returns {{ dotClass: string, label: string }} */
export function latencyMeta(latencyMs) {
  const ms = Number(latencyMs)
  if (!Number.isFinite(ms)) {
    return { dotClass: 'bg-neutral-300', label: '—' }
  }
  if (ms > 5000) return { dotClass: 'bg-red-500', label: 'Lento' }
  if (ms > 2000) return { dotClass: 'bg-amber-500', label: 'Normal' }
  return { dotClass: 'bg-emerald-500', label: 'Rápido' }
}

export function shortUrl(url) {
  if (!url) return '—'
  try {
    const u = new URL(url)
    const path = u.pathname + u.search
    return path.length > 64 ? `${path.slice(0, 64)}…` : path
  } catch {
    return url.length > 64 ? `${url.slice(0, 64)}…` : url
  }
}

export function typeLabel(row) {
  if (row?.webhook_topic_label) return row.webhook_topic_label
  return row?.endpoint_group ?? row?.provider ?? '—'
}

/**
 * Filtro cliente sobre filas ya cargadas (toolbar search).
 * @param {Array<object>} logs
 * @param {string} query
 */
export function filterLogs(logs, query) {
  const q = String(query || '').trim().toLowerCase()
  if (!q) return Array.isArray(logs) ? logs : []
  return (logs || []).filter((row) => {
    const hay = [
      row.id,
      row.correlation_id,
      row.provider,
      row.endpoint_group,
      row.webhook_topic,
      row.webhook_topic_label,
      row.url,
      row.method,
      row.direction,
      row.response_status,
      row.connection?.external_user_id,
      row.sync_run_id,
    ]
      .filter((v) => v != null && v !== '')
      .join(' ')
      .toLowerCase()
    return hay.includes(q)
  })
}

export function buildCurlCommand(log) {
  if (!log || log.direction === 'in') return ''
  const method = (log.method || 'GET').toUpperCase()
  const url = log.url || ''
  const lines = [`curl -X ${method} '${url}'`]

  const headers = log.request_headers_redacted
  if (headers && typeof headers === 'object') {
    for (const [k, v] of Object.entries(headers)) {
      if (v == null) continue
      const value = Array.isArray(v) ? v.join(', ') : String(v)
      lines.push(`  -H '${k}: ${value.replace(/'/g, "'\\''")}'`)
    }
  }

  const body = log.request_body_redacted
  if (body && method !== 'GET' && method !== 'HEAD') {
    const escaped = String(body).replace(/'/g, "'\\''")
    lines.push(`  --data-raw '${escaped}'`)
  }

  return lines.join(' \\\n')
}

export function useSyncHttpLogs() {
  return {
    getTypeTag,
    getTypeTagClass,
    directionBadgeClass,
    directionLabel,
    latencyMeta,
    shortUrl,
    typeLabel,
    filterLogs,
    buildCurlCommand,
  }
}
