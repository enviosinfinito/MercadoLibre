/** @type {import('../types.js').FilterSchema} */
export const returnsFilterSchema = {
  excludeKeys: ['page', 'per_page', 'tab', 'connection_ids'],
  filters: [
    {
      key: 'q',
      type: 'string',
      label: 'Buscar',
      default: '',
      debounceMs: 350,
      chip: { strategy: 'single' },
    },
    {
      key: 'period',
      type: 'string',
      label: 'Periodo',
      default: 'last_30_days',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => {
          if (!value || value === 'last_30_days') return ''
          const labels = {
            today: 'Hoy',
            yesterday: 'Ayer',
            last_7_days: 'Últimos 7 días',
            last_30_days: 'Últimos 30 días',
            this_month: 'Este mes',
            previous_month: 'Mes anterior',
            last_90_days: 'Últimos 90 días',
            year_to_date: 'Año actual',
            custom: 'Personalizado',
          }
          return labels[String(value)] || String(value)
        },
      },
    },
    {
      key: 'from',
      type: 'date',
      label: 'Desde',
      default: '',
      chip: { strategy: 'hidden' },
    },
    {
      key: 'to',
      type: 'date',
      label: 'Hasta',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (_value, ctx) => {
          if ((ctx.state?.period ?? '') !== 'custom') return ''
          const from = ctx.state?.from
          const to = ctx.state?.to
          if (from && to) return `${from} → ${to}`
          if (from) return `Desde ${from}`
          if (to) return `Hasta ${to}`
          return ''
        },
      },
    },
    {
      key: 'sort',
      type: 'string',
      label: 'Orden',
      default: 'risk_score',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => {
          if (!value || value === 'risk_score') return ''
          const labels = {
            risk_score: 'Risk Score',
            return_rate: 'Tasa',
            returned_amount: 'Importe',
            returned_units: 'Unidades',
          }
          return labels[String(value)] || String(value)
        },
      },
    },
    {
      key: 'anomalies_only',
      type: 'boolean',
      label: 'Solo anomalías',
      default: false,
      chip: {
        strategy: 'single',
        resolveLabel: (value) => (value ? 'Sí' : ''),
      },
    },
    {
      key: 'above_historical',
      type: 'boolean',
      label: 'Sobre histórico',
      default: false,
      chip: {
        strategy: 'single',
        resolveLabel: (value) => (value ? 'Sí' : ''),
      },
    },
  ],
}
