export const CASH_CONCEPT = {
  sale: 'sale',
  shipping_credit: 'shipping_credit',
  shipping_debit: 'shipping_debit',
  refund: 'refund',
  chargeback: 'chargeback',
  dispute: 'dispute',
  payout_hold: 'payout_hold',
  withdrawal: 'withdrawal',
  cashback: 'cashback',
  other: 'other',
}

const LABELS = {
  sale: 'Cobro de la venta',
  shipping_credit: 'Envío que pagó el comprador',
  shipping_debit: 'Débito de envío',
  refund: 'Reembolso',
  chargeback: 'Contracargo',
  dispute: 'Reserva / reclamo',
  payout_hold: 'Reserva para retiro',
  withdrawal: 'Retiro al banco',
  cashback: 'Ajuste / cashback',
  other: 'Otro movimiento MP',
}

const TYPE_LABELS = {
  RESERVE_FOR_DISPUTE: 'Reserva por reclamo',
  MEDIATION: 'Mediación (reclamo)',
  DISPUTE: 'Reclamo',
  DISPUTE_SHIPPING: 'Reclamo de envío',
  RESERVE_FOR_REFUND: 'Reserva por reembolso',
  RESERVE_FOR_BPP_SHIPPING_RETURN: 'Reserva por devolución',
  RESERVE_FOR_TIME_PERIOD: 'Reserva temporal',
  RESERVE_FOR_DEBT_PAYMENT: 'Reserva por deuda',
  RESERVE_FOR_PAYMENT: 'Reserva de cobro',
  RESERVE_FOR_PAYOUT: 'Reserva para retiro',
  REFUND_SHIPPING: 'Reembolso de envío',
  CHARGEBACK_SHIPPING: 'Contracargo de envío',
}

const HINTS = {
  shipping_credit: 'Lo que el comprador pagó de envío en el checkout. No es el costo de envío que te descontaron en el cobro.',
  shipping_debit: 'Descuento de logística (no es lo que pagó el comprador).',
  sale: 'Neto del cobro del comprador, ya con comisión, envío e impuestos.',
  dispute: 'MP retuvo este dinero por un reclamo o mediación. No está disponible en tu saldo hasta que se resuelva.',
  payout_hold: 'MP apartó este monto para un retiro. No es un reclamo.',
  cashback: 'Ajuste o cashback reportado por Mercado Pago.',
  refund: 'Devolución total o parcial al comprador.',
  chargeback: 'Contracargo: el dinero salió de tu saldo.',
}

export function cashConceptLabel(concept, fallbackType = '', entryType = '', externalId = '') {
  const tx = String(fallbackType || '').toUpperCase()
  if (TYPE_LABELS[tx]) return TYPE_LABELS[tx]
  if (concept && LABELS[concept]) return LABELS[concept]
  const fromType = conceptFromMpType(fallbackType, entryType, externalId)
  return LABELS[fromType] || LABELS.other
}

export function cashConceptHint(concept) {
  return HINTS[concept] || null
}

export function conceptFromMpType(transactionType = '', entryType = '', externalId = '') {
  const tx = String(transactionType || '').toUpperCase()
  const entry = String(entryType || '').toLowerCase()
  const id = String(externalId || '').trim()

  if (entry === 'withdrawal' || ['PAYOUTS', 'PAYOUT', 'WITHDRAWAL', 'BANK_TRANSFER'].includes(tx)) {
    return CASH_CONCEPT.withdrawal
  }
  if (tx === 'REFUND' || tx.startsWith('REFUND')) return CASH_CONCEPT.refund
  if (tx === 'CHARGEBACK' || tx.includes('CHARGEBACK')) return CASH_CONCEPT.chargeback
  if (tx === 'RESERVE_FOR_PAYOUT') return CASH_CONCEPT.payout_hold
  if (tx === 'DISPUTE' || tx === 'MEDIATION' || tx.includes('DISPUTE') || tx.startsWith('RESERVE_')) {
    return CASH_CONCEPT.dispute
  }
  if (tx === 'SHIPPING' || tx === 'SETTLEMENT_SHIPPING' || tx.includes('SHIPPING')) {
    return CASH_CONCEPT.shipping_credit
  }
  if (tx === 'CASHBACK' || id.toLowerCase().startsWith('cashback_')) return CASH_CONCEPT.cashback
  if (tx === 'PAYMENT' || tx === 'SETTLEMENT') return CASH_CONCEPT.sale
  if (/^\d{1,12}$/.test(id) && !/^20000\d{11}$/.test(id)) return CASH_CONCEPT.shipping_credit
  if (/^20000\d{11}$/.test(id)) return CASH_CONCEPT.sale
  return CASH_CONCEPT.other
}

export function ledgerFilterLabel(entryType) {
  const map = {
    settlement: 'Cobros',
    release: 'Liberaciones',
    withdrawal: 'Retiros al banco',
    refund: 'Reembolsos',
    chargeback: 'Contracargos',
    ads_charge: 'Publicidad',
    storage_charge: 'Storage',
    adjustment: 'Ajustes',
  }
  return map[entryType] || entryType || '—'
}

export function matchMethodLabel(method) {
  const map = {
    source_id: 'Por ID de cobro',
    order_id: 'Por orden',
    withdrawal_fifo: 'Retiro FIFO',
  }
  return map[method] || method || '—'
}
