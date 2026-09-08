import { connectionFilterLabel } from '@/lib/connectionLabel'

/** @type {import('../types.js').FilterSchema} */
export const questionsFilterSchema = {
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
      key: 'tab',
      type: 'string',
      label: 'Tab',
      default: 'all',
      chip: { strategy: 'hidden' },
    },
  ],
}
