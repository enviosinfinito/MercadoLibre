/** @type {import('../types.js').FilterSchema} */
export const productsFilterSchema = {
  excludeKeys: ['page', 'per_page', 'sort', 'direction'],
  filters: [
    {
      key: 'q',
      type: 'string',
      label: 'Buscar',
      default: '',
      debounceMs: 350,
      excludeFromUrl: true,
      chip: { strategy: 'hidden' },
    },
    {
      key: 'archived',
      type: 'boolean',
      label: 'Archivados',
      default: false,
      chip: { strategy: 'single' },
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
