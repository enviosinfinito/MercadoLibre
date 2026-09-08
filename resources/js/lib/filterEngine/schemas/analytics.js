import { connectionFilterLabel } from '@/lib/connectionLabel'

/** @type {import('../types.js').FilterSchema} */
export const analyticsFilterSchema = {
  excludeKeys: ['page', 'per_page', 'sort', 'direction'],
  filters: [
    {
      key: 'date_from',
      type: 'date',
      label: 'Desde',
      default: '',
      chip: { strategy: 'hidden' },
    },
    {
      key: 'date_to',
      type: 'date',
      label: 'Hasta',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (_value, ctx) => {
          const from = ctx.state?.date_from
          const to = ctx.state?.date_to
          if (from && to) return `${from} → ${to}`
          if (from) return `Desde ${from}`
          if (to) return `Hasta ${to}`
          return ''
        },
      },
    },
    {
      key: 'connection_id',
      type: 'string',
      label: 'Conexión',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value, ctx) => {
          const list = ctx.catalogs?.connections ?? []
          const c = list.find((x) => String(x.id) === String(value))
          if (!c) return String(value)
          return connectionFilterLabel(c)
        },
      },
    },
    {
      key: 'date_field',
      type: 'string',
      label: 'Campo fecha',
      default: 'ordered_at',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => (value === 'ordered_at' ? null : String(value)),
      },
    },
  ],
}
