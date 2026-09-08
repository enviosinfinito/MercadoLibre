import axios from 'axios'

/**
 * Start an async export with optimistic progress modal events.
 *
 * @param {object} options
 * @param {string} options.targetModule
 * @param {'ids'|'filter'} options.selectionMode
 * @param {number[]} [options.ids]
 * @param {Record<string, unknown>} [options.filters]
 * @param {Record<string, unknown>} [options.query]
 * @param {number|null} [options.exportConfigId]
 * @param {number|null} [options.exportPresetId]
 * @param {string[]} [options.columns]
 * @param {string} [options.referencesFormat]
 * @param {string} [options.label]
 * @param {number} [options.totalRowsHint]
 */
export async function startExport(options) {
  const pendingToken = `pending-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  const stableKey = pendingToken
  const label = options.label || options.targetModule || 'Export'

  window.dispatchEvent(
    new CustomEvent('export-start', {
      detail: {
        token: pendingToken,
        stableKey,
        status: 'queued',
        progress: 0,
        total_rows: options.totalRowsHint ?? null,
        estimated_time: '~…',
        modalMessage: label,
        target_module: options.targetModule,
      },
    }),
  )

  try {
    const { data } = await axios.post(
      route('exports.start'),
      {
        target_module: options.targetModule,
        selection_mode: options.selectionMode,
        ids: options.ids ?? undefined,
        filters: options.filters ?? {},
        query: options.query ?? undefined,
        export_config_id: options.exportConfigId ?? undefined,
        export_preset_id: options.exportPresetId ?? undefined,
        columns: options.columns ?? undefined,
        references_format: options.referencesFormat ?? undefined,
      },
      { headers: { Accept: 'application/json' } },
    )

    window.dispatchEvent(
      new CustomEvent('export-pending-resolve', {
        detail: {
          pendingToken,
          token: data.token,
          total_rows: data.total_rows,
          estimated_time: data.estimated_time,
          stableKey,
        },
      }),
    )

    return data
  } catch (error) {
    window.dispatchEvent(
      new CustomEvent('export-pending-fail', {
        detail: { pendingToken, stableKey, error },
      }),
    )
    throw error
  }
}
