/**
 * Recursos CRUD embebidos cross-context (Tipo A).
 * Registrar entradas aquí al añadir editores in-slide.
 * @type {Record<string, object>}
 */
export const EMBEDDED_RESOURCES = {}

/** Address-style self-contained slides (fetch interno). */
export const EMBEDDED_ADDRESS_RESOURCE = null

export function getEmbeddedResource(kind) {
  return EMBEDDED_RESOURCES[kind] ?? null
}

export function resolveEmbeddedTitle(kind, payload) {
  const def = getEmbeddedResource(kind)
  if (!def) return 'Detalle'
  if (typeof def.title === 'function') {
    return def.title(payload)
  }
  return def.title ?? 'Detalle'
}

export const EMBEDDED_RESOURCE_KINDS = Object.keys(EMBEDDED_RESOURCES)
