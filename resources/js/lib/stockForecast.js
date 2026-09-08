/**
 * @param {{ days_of_cover?: number|null, units_per_day?: number, window_days?: number }|null|undefined} forecast
 * @returns {string}
 */
export function formatStockDepletionLabel(forecast) {
  if (!forecast) return '—'
  if (forecast.days_of_cover === 0) return 'Agotado'
  if (forecast.days_of_cover == null) return 'Sin ritmo'
  const days = Number(forecast.days_of_cover)
  if (!Number.isFinite(days)) return '—'
  if (days < 1) return '<1 día'
  const rounded = Math.round(days)
  return `~${rounded} ${rounded === 1 ? 'día' : 'días'}`
}

/**
 * @param {{ days_of_cover?: number|null, units_per_day?: number, window_days?: number, velocity_window_days?: number, units_sold_window?: number, stock_basis?: string, mode?: string }|null|undefined} forecast
 * @param {{ stockout_count?: number, at_risk_count?: number, bottleneck_sku?: string|null }|null|undefined} assortment
 * @returns {string}
 */
export function formatStockDepletionHint(forecast, assortment = null) {
  if (!forecast) return ''

  if (forecast.mode === 'bottleneck' && assortment) {
    const parts = ['surtido']
    const stockouts = Number(assortment.stockout_count ?? 0)
    const atRisk = Number(assortment.at_risk_count ?? 0)
    if (stockouts > 0) parts.push(`${stockouts} agotada${stockouts === 1 ? '' : 's'}`)
    if (atRisk > 0) parts.push(`${atRisk} en riesgo`)
    if (assortment.bottleneck_sku && forecast.days_of_cover != null) {
      parts.push(`peor: ${assortment.bottleneck_sku}`)
    }
    const rate = Number(forecast.units_per_day ?? 0)
    if (rate > 0) {
      parts.push(`${rate.toLocaleString('es-MX', { maximumFractionDigits: 2 })} u/día`)
    }
    if (forecast.stock_basis === 'channel') parts.push('base canal')
    else if (forecast.stock_basis === 'internal') parts.push('base interno')
    return parts.join(' · ')
  }

  const rate = Number(forecast.units_per_day ?? 0)
  const windowDays = Number(forecast.velocity_window_days ?? forecast.window_days ?? 14)
  const sold = Number(forecast.units_sold_window ?? 0)
  const rateLabel = rate > 0
    ? `${rate.toLocaleString('es-MX', { maximumFractionDigits: 2 })} u/día`
    : '0 u/día'
  const basis = forecast.stock_basis === 'channel'
    ? 'base canal'
    : forecast.stock_basis === 'internal'
      ? 'base interno'
      : null
  const parts = [rateLabel]
  if (basis) parts.push(basis)
  parts.push(`últimos ${windowDays} días (${sold} u)`)
  return parts.join(' · ')
}

/**
 * @param {{ days_of_cover?: number|null }|null|undefined} forecast
 * @returns {'critical'|'warning'|null}
 */
export function stockDepletionSeverity(forecast) {
  if (!forecast || forecast.days_of_cover == null) return null
  const days = Number(forecast.days_of_cover)
  if (!Number.isFinite(days)) return null
  if (days < 7) return 'critical'
  if (days < 14) return 'warning'
  return null
}

/**
 * Urgency sort: stockout → low cover → no velocity → ok.
 * @param {{ forecast?: { days_of_cover?: number|null, sellable_qty?: number } }} row
 * @returns {number}
 */
export function stockVariantUrgencyRank(row) {
  const forecast = row?.forecast || {}
  const days = forecast.days_of_cover
  if (days === 0 || days === 0.0) return 0
  if (days != null && Number(days) < 14) return 1 + Number(days) / 1000
  if (days == null) return 500
  return 1000 + Number(days)
}

/**
 * @typedef {'danger'|'attention'|'healthy'|'unknown'} AssortmentHealthLevel
 */

/**
 * @param {{ stockout_count?: number, at_risk_count?: number, ok_count?: number, no_velocity_count?: number, variant_count?: number }|null|undefined} assortment
 * @param {{ days_of_cover?: number|null, mode?: string }|null|undefined} forecast
 * @returns {{ level: AssortmentHealthLevel, title: string, subtitle: string }}
 */
export function assortmentHealth(assortment, forecast = null) {
  const stockouts = Number(assortment?.stockout_count ?? 0)
  const atRisk = Number(assortment?.at_risk_count ?? 0)
  const ok = Number(assortment?.ok_count ?? 0)
  const noVelocity = Number(assortment?.no_velocity_count ?? 0)
  const days = forecast?.days_of_cover

  if (stockouts > 0 || days === 0) {
    return {
      level: 'danger',
      title: 'Peligro',
      subtitle: stockouts > 0
        ? `${stockouts} talla${stockouts === 1 ? '' : 's'} agotada${stockouts === 1 ? '' : 's'} — el surtido está roto`
        : 'Cuello de botella sin cobertura',
    }
  }

  if (atRisk > 0 || (days != null && Number(days) < 14)) {
    return {
      level: 'attention',
      title: 'Atención',
      subtitle: atRisk > 0
        ? `${atRisk} talla${atRisk === 1 ? '' : 's'} con menos de 14 días`
        : 'Cobertura corta en el cuello de botella',
    }
  }

  if (ok > 0 || (days != null && Number(days) >= 14)) {
    return {
      level: 'healthy',
      title: 'Sano',
      subtitle: ok > 0
        ? `${ok} talla${ok === 1 ? '' : 's'} con cobertura ≥ 14 días`
        : 'Cobertura saludable',
    }
  }

  if (noVelocity > 0) {
    return {
      level: 'unknown',
      title: 'Sin ritmo',
      subtitle: `${noVelocity} talla${noVelocity === 1 ? '' : 's'} con stock pero sin ventas recientes atribuidas`,
    }
  }

  return {
    level: 'unknown',
    title: 'Sin datos',
    subtitle: 'No hay suficiente información de surtido',
  }
}
