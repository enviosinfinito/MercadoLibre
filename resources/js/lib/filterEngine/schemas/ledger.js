/** @type {import('../types.js').FilterSchema} */
export const ledgerFilterSchema = {
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
      key: 'variant_id',
      type: 'string',
      label: 'SKU',
      default: '',
      chip: { strategy: 'single' },
    },
    {
      key: 'movement_type',
      type: 'string',
      label: 'Tipo',
      default: '',
      chip: { strategy: 'single' },
    },
    {
      key: 'warehouse_id',
      type: 'string',
      label: 'Almacén',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value, ctx) => {
          const list = ctx.catalogs?.warehouses ?? []
          const w = list.find((x) => String(x.id) === String(value))
          return w ? w.code : String(value)
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
