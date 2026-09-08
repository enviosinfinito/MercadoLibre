/**
 * Cascade pruning: when a parent filter changes, remove invalid child selections.
 * @param {import('./types.js').FilterSchema} schema
 * @param {Record<string, unknown>} state
 * @param {string} changedKey
 * @param {{ getAllowedIds?: (parentKey: string, state: object) => Record<string, Set<number|string>> }} [rules]
 */
export function pruneCascadeSelections(schema, state, changedKey, rules = {}) {
  const def = (schema.filters || []).find((d) => d.key === changedKey)
  if (!def?.cascade?.prunes?.length) return state

  const next = { ...state }
  const allowedByParent = rules.getAllowedIds?.(changedKey, next) ?? {}

  for (const childKey of def.cascade.prunes) {
    const childDef = (schema.filters || []).find((d) => d.key === childKey)
    if (!childDef) continue

    const allowed = allowedByParent[childKey]
    if (!allowed || !(next[childKey] instanceof Array)) continue

    next[childKey] = next[childKey].filter((id) =>
      allowed.has(Number(id)) || allowed.has(String(id))
    )
  }

  return next
}
