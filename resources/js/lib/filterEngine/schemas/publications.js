import { connectionFilterLabel } from '@/lib/connectionLabel'

/** @type {import('../types.js').FilterSchema} */
export const publicationsFilterSchema = {
  excludeKeys: ['page', 'per_page', 'sort', 'direction'],
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
      chip: {
        strategy: 'single',
        resolveLabel: (value) => {
          if (value === 'active') return 'Activa'
          if (value === 'paused') return 'Pausada'
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
      key: 'matched',
      type: 'string',
      label: 'Match',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => {
          if (value === 'yes') return 'Con match'
          if (value === 'no') return 'Sin match'
          return null
        },
      },
    },
    {
      key: 'without_cost',
      type: 'boolean',
      label: 'Sin costo',
      default: false,
      chip: { strategy: 'single' },
    },
  ],
}
