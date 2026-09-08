import { connectionFilterLabel } from '@/lib/connectionLabel'

/** @type {import('../types.js').FilterSchema} */
export const ordersFilterSchema = {
  excludeKeys: ['page', 'per_page', 'sort', 'direction', 'tab'],
  filters: [
    {
      key: 'q',
      type: 'string',
      label: 'Buscar',
      default: '',
      debounceMs: 350,
    },
    {
      key: 'status',
      type: 'string',
      label: 'Estado',
      default: '',
      chip: { strategy: 'hidden' },
    },
    {
      key: 'tab',
      type: 'string',
      label: 'Tab',
      default: 'all',
      chip: { strategy: 'hidden' },
    },
    {
      key: 'release_issue',
      type: 'string',
      label: 'Liberación',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => {
          if (value === 'overdue') return 'Liberación atrasada'
          if (value === 'orphan') return 'Huérfana'
          if (value === 'unreleased') return 'Sin liberar'
          if (value === 'held') return 'En reserva'
          return String(value)
        },
      },
    },
    {
      key: 'connection_id',
      type: 'string',
      label: 'Canal',
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
          const from = ctx.state?.from
          const to = ctx.state?.to
          if (from && to) return `${from} → ${to}`
          if (from) return `Desde ${from}`
          if (to) return `Hasta ${to}`
          return ''
        },
      },
    },
  ],
}
