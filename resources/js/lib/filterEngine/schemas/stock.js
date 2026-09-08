/** @type {import('../types.js').FilterSchema} */
export const stockFilterSchema = {
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
      key: 'tab',
      type: 'string',
      label: 'Tab',
      default: 'internal',
      chip: { strategy: 'hidden' },
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
      key: 'low_stock',
      type: 'boolean',
      label: 'Se acaba pronto',
      default: false,
      chip: { strategy: 'single' },
    },
    {
      key: 'unmatched',
      type: 'boolean',
      label: 'Sin match',
      default: false,
      chip: { strategy: 'single' },
    },
    {
      key: 'channel_mismatch',
      type: 'boolean',
      label: 'Desfase canal',
      default: false,
      chip: { strategy: 'single' },
    },
  ],
}
