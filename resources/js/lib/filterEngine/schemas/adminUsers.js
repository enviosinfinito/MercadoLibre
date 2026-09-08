/** @type {import('../types.js').FilterSchema} */
export const adminUsersFilterSchema = {
  excludeKeys: ['page', 'per_page', 'sort', 'direction'],
  filters: [
    {
      key: 'search',
      type: 'string',
      label: 'Buscar',
      default: '',
      debounceMs: 350,
    },
  ],
}
