/** @type {import('../types.js').FilterSchema} */
export const adminConnectionsFilterSchema = {
  excludeKeys: ['page', 'per_page', 'sort', 'direction'],
  filters: [
    {
      key: 'search',
      type: 'string',
      label: 'Buscar',
      default: '',
      debounceMs: 350,
    },
    {
      key: 'provider',
      type: 'string',
      label: 'Provider',
      default: '',
      chip: { strategy: 'single' },
    },
    {
      key: 'status',
      type: 'string',
      label: 'Estado',
      default: '',
      chip: { strategy: 'single' },
    },
  ],
}
