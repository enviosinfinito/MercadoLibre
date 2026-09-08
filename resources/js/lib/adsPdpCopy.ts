/**
 * Plain-language Spanish labels for Ads assistant (PDP).
 */
export const ADS_PDP = {
  roas: {
    label: 'Ventas por cada $1 de ads',
    plain: 'Cuántas ventas atribuye Mercado Ads por cada peso que invertís en publicidad.',
    why: 'Si está bajo tu meta, los ads comen tu ganancia.',
    how: 'Ventas atribuidas ÷ inversión (Mercado Ads).',
  },
  target: {
    label: 'Meta para cuidar ganancia',
    plain: 'Mínimo de ventas por $1 de ads para no comer tu margen.',
    why: 'Sale de tu ganancia estimada, no de un número mágico de ML.',
    how: 'Margen × % del margen que podés destinar a ads.',
  },
  waste: {
    label: 'Desperdicio',
    plain: 'Gastaste en ads y ML no atribuyó ventas en la ventana.',
    why: 'Es plata que salió sin retorno medible; no se inventa como fee de una orden orgánica.',
    how: 'Suma de gasto en ítems/días con 0 revenue o unidades atribuidas.',
  },
  spend: {
    label: 'Inversión',
    plain: 'Lo que reporta Mercado Ads como gasto en el periodo.',
    why: 'Es la base del rendimiento y del desperdicio.',
    how: 'Suma de cost diario de Product Ads.',
  },
  tacos: {
    label: '% del ingreso en ads (TACoS)',
    plain: 'Qué porcentaje de tus ventas totales (orgánicas + ads) se fue en publicidad.',
    why: 'Métrica de negocio; no confundir con ACoS/ROAS (solo atribuido).',
    how: 'Inversión ads ÷ GMV local del ítem o cuenta.',
  },
  acos: {
    label: 'ACoS (atribuido)',
    plain: 'Qué % del revenue atribuido por ML se fue en ads.',
    why: 'Solo cuenta ventas que ML marca como del Product Ad.',
    how: 'Inversión ÷ total_amount (directo + indirecto).',
  },
  residual: {
    label: 'Ads sin cargar a órdenes',
    plain: 'Gasto residual: waste (ML atribuyó $0) o shortfall vs órdenes sync.',
    why: 'Si es alto por waste, revisá la campaña; si es shortfall, sincronizá órdenes.',
    how: 'Eventos expected_advertising_unallocated.',
  },
  direct: {
    label: 'Ventas directas',
    plain: 'Revenue que ML atribuye a clics directos del ad.',
    how: 'direct_amount de Product Ads.',
  },
  indirect: {
    label: 'Ventas asistidas',
    plain: 'Revenue asistido por el ad (atribución indirecta de ML).',
    how: 'indirect_amount de Product Ads.',
  },
  organic: {
    label: 'Orgánico ML',
    plain: 'Ventas del ítem que ML marca sin publicidad.',
    why: 'No se cargan como ads en el P&L de la orden.',
    how: 'organic_units_amount / organic_units_quantity.',
  },
  coverageShort: 'Nos faltan órdenes sincronizadas vs lo que ML atribuye.',
  coverageOk: 'Tus órdenes cubren lo que ML atribuye a ads.',
  coverageOrganic:
    'Tenés más ventas locales que las atribuidas a ads (incluye orgánicas). No inventamos conversiones.',
  coverageWaste:
    'Hubo gasto y ML no atribuyó ventas: waste/overhead, no fee de orden orgánica.',
} as const

export function priorityLabelEs(priority: string): string {
  if (priority === 'high') return 'Alta'
  if (priority === 'medium') return 'Media'
  if (priority === 'low') return 'Baja'
  return priority
}

export function statusLabelEs(status: string, fallback?: string | null): string {
  if (fallback) return fallback
  if (status === 'red') return 'Actuar'
  if (status === 'yellow') return 'Cuidado'
  if (status === 'green') return 'Bien'
  return status
}
