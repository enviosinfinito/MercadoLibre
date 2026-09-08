/** @type {import('../types.js').FilterSchema} */
export const receiptsFilterSchema = {
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
  ],
}
