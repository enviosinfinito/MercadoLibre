/** @type {import('../types.js').FilterSchema} */
export const claimsFilterSchema = {
  excludeKeys: ['page', 'per_page', 'sort', 'direction'],
  filters: [
    {
      key: 'q',
      type: 'string',
      label: 'Buscar',
      default: '',
      debounceMs: 350,
      chip: { strategy: 'single' },
    },
    {
      key: 'status',
      type: 'string',
      label: 'Estado',
      default: '',
      chip: {
        strategy: 'single',
        resolveLabel: (value) => {
          if (value === 'opened') return 'Abiertos'
          if (value === 'closed') return 'Cerrados'
          return String(value)
        },
      },
    },
  ],
}
