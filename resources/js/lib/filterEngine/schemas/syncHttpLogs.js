import { connectionFilterLabel } from '@/lib/connectionLabel'

/** @type {import('../types.js').FilterSchema} */
export const syncHttpLogsFilterSchema = {
  excludeKeys: ['page', 'per_page', 'sort', 'direction'],
  filters: [
    {
      key: 'q',
      type: 'string',
      label: 'Buscar',
      default: '',
      debounceMs: 350,
      chip: { strategy: 'hidden' },
    },
    {
      key: 'http_direction',
      type: 'string',
      label: 'Dirección',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => {
          if (value === 'out') return 'Salidas'
          if (value === 'in') return 'Entradas'
          return String(value)
        },
      },
    },
    {
      key: 'status',
      type: 'string',
      label: 'Estado',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => {
          if (value === 'success') return 'Éxito'
          if (value === 'error') return 'Errores'
          return String(value)
        },
      },
    },
    {
      key: 'topic',
      type: 'string',
      label: 'Tipo webhook',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value, ctx) => {
          const list = ctx.catalogs?.webhookTopics ?? []
          const t = list.find((x) => String(x.value) === String(value))
          return t?.label ?? String(value)
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
    {
      key: 'orphan',
      type: 'string',
      label: 'Sin corrida',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => (value === '1' || value === 1 || value === true ? 'Sin corrida' : ''),
      },
    },
    {
      key: 'body_search',
      type: 'string',
      label: 'Body',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => (value ? `Body: ${String(value).slice(0, 24)}` : ''),
      },
    },
  ],
}
