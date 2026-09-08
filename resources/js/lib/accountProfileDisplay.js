export function formatPhone(phone) {
  if (!phone || typeof phone !== 'object') return null
  const area = String(phone.area_code ?? '').trim()
  const number = String(phone.number ?? '').trim()
  const ext = String(phone.extension ?? '').trim()
  if (!area && !number) return null
  const base = `${area} ${number}`.trim()
  return ext ? `${base} ext. ${ext}` : base
}

export function maskEmail(email) {
  if (!email || typeof email !== 'string') return null
  const [localPart, domain] = email.split('@')
  if (!domain) return email
  if (localPart.length <= 2) return `${localPart[0] ?? '*'}***@${domain}`
  return `${localPart.slice(0, 2)}***@${domain}`
}

export function formatPct(rate) {
  if (rate == null || Number.isNaN(Number(rate))) return null
  return `${(Number(rate) * 100).toFixed(2)}%`
}

export function reputationLevelLabel(level) {
  const map = {
    '5_green': 'Verde',
    '4_light_green': 'Verde claro',
    '3_yellow': 'Amarillo',
    '2_orange': 'Naranja',
    '1_red': 'Rojo',
  }
  return map[level] ?? level ?? null
}

export function bandLabel(band) {
  const map = {
    leader: 'Líderes',
    green: 'Verde',
    yellow: 'Amarillo',
    orange: 'Naranja',
    red: 'Rojo',
  }
  return map[band] ?? band ?? null
}

export function allowLabel(allow) {
  if (allow === true) return 'Permitido'
  if (allow === false) return 'Bloqueado'
  return null
}

export function boolLabel(value) {
  if (value === true) return 'Sí'
  if (value === false) return 'No'
  return null
}

export function formatDateTime(iso) {
  if (!iso) return null
  try {
    return new Date(iso).toLocaleString('es-MX', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return iso
  }
}
