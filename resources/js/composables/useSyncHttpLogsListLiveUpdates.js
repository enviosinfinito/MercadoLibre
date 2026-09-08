import {
  getOrdersListScrollEl,
  getOrdersListScrollTop,
  scrollOrdersListToTop,
  useOrdersListLiveUpdates,
} from '@/composables/useOrdersListLiveUpdates'

export {
  getOrdersListScrollEl as getSyncHttpLogsListScrollEl,
  getOrdersListScrollTop as getSyncHttpLogsListScrollTop,
  scrollOrdersListToTop as scrollSyncHttpLogsListToTop,
}

/**
 * Poll de novedades para Sync HTTP Logs Index (since_id + filtros actuales).
 * Reutiliza el motor de Órdenes.
 */
export function useSyncHttpLogsListLiveUpdates(options) {
  return useOrdersListLiveUpdates({
    pollIntervalMs: 15000,
    ...options,
  })
}

/**
 * URL del poll conservando filtros activos (sin page / since_id).
 * @returns {string}
 */
export function buildSyncHttpLogsListUpdatesUrl() {
  const u = new URL(window.location.href)
  const named = typeof route === 'function' ? route('sync-logs.list-updates') : '/sync-logs/list-updates'
  try {
    const namedUrl = new URL(named, window.location.origin)
    u.pathname = namedUrl.pathname
  } catch {
    u.pathname = '/sync-logs/list-updates'
  }
  u.searchParams.delete('page')
  u.searchParams.delete('since_id')
  const qs = u.searchParams.toString()
  return qs ? `${u.pathname}?${qs}` : u.pathname
}
