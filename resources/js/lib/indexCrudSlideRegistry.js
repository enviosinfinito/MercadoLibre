/**
 * Editores CRUD en propio listado (Tipo B).
 * loadMode: 'props' | 'fetch' | 'record'
 * Registrar entradas aquí al migrar Indexes a IndexCrudSlide.
 */
export const INDEX_CRUD_SLIDES = {}

export function getIndexCrudSlide(kind) {
  return INDEX_CRUD_SLIDES[kind] ?? null
}

export function resolveIndexCrudTitle(kind, record) {
  const def = getIndexCrudSlide(kind)
  if (!def) return 'Editor'
  if (typeof def.title === 'function') {
    return def.title(record)
  }
  return def.title ?? 'Editor'
}
