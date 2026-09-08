import { unref, watch } from 'vue'
import { useBackgroundPoll } from '@/composables/useBackgroundPoll'
import { getListScrollRoot } from '@/composables/useInfiniteList'

const SCROLL_TOP_THRESHOLD_PX = 80

/**
 * @returns {HTMLElement|Window|null}
 */
export function getOrdersListScrollEl(hintEl = null) {
  if (typeof document === 'undefined') return null

  const fromHint = hintEl ? getListScrollRoot(hintEl) : null
  const root = fromHint
    || document.querySelector('[data-content-safe-area]')
    || document.querySelector('.content-slot')

  if (root && typeof window !== 'undefined') {
    const style = window.getComputedStyle(root)
    const oy = style.overflowY
    const canScroll = oy === 'auto' || oy === 'scroll' || oy === 'overlay'
    if (canScroll) return root
  }

  return typeof window !== 'undefined' ? window : null
}

/**
 * @param {HTMLElement|Window|null} el
 */
export function getOrdersListScrollTop(el) {
  if (!el) return 0
  if (el === window || el === document) {
    return window.scrollY
      || document.documentElement.scrollTop
      || document.body.scrollTop
      || 0
  }
  return el.scrollTop ?? 0
}

/**
 * @param {HTMLElement|Window|null} el
 */
export function scrollOrdersListToTop(el) {
  if (!el) return
  if (el === window || el === document) {
    window.scrollTo({ top: 0, behavior: 'smooth' })
    return
  }
  el.scrollTo({ top: 0, behavior: 'smooth' })
}

/**
 * Poll de novedades para Orders Index (since_id + filtros actuales).
 *
 * @param {Object} options
 * @param {import('vue').MaybeRefOrGetter<boolean>} options.enabled
 * @param {import('vue').MaybeRefOrGetter<number>} options.sinceId
 * @param {import('vue').MaybeRefOrGetter<boolean>} options.isLoading
 * @param {import('vue').MaybeRefOrGetter<boolean>} options.isLoadingMore
 * @param {import('vue').MaybeRefOrGetter<boolean>} options.hasLoadedBeyondFirstPage
 * @param {() => HTMLElement|Window|null} [options.getScrollEl]
 * @param {(items: any[]) => void} [options.onPrepend]
 * @param {(items: any[]) => void} [options.onPending]
 * @param {() => string} options.buildUpdatesUrl
 * @param {number} [options.pollIntervalMs=30000]
 */
export function useOrdersListLiveUpdates({
  enabled,
  sinceId,
  isLoading,
  isLoadingMore,
  hasLoadedBeyondFirstPage,
  getScrollEl = getOrdersListScrollEl,
  onPrepend,
  onPending,
  buildUpdatesUrl,
  pollIntervalMs = 30000,
}) {
  function isScrollNearTop() {
    const el = getScrollEl?.()
    if (!el) return true
    return getOrdersListScrollTop(el) <= SCROLL_TOP_THRESHOLD_PX
  }

  async function poll({ getSignal } = {}) {
    if (!unref(enabled)) return
    if (unref(isLoading) || unref(isLoadingMore)) return
    if (unref(hasLoadedBeyondFirstPage)) return

    const id = Number(unref(sinceId))
    if (!Number.isFinite(id) || id <= 0) return

    try {
      const url = new URL(buildUpdatesUrl(), window.location.origin)
      url.searchParams.set('since_id', String(id))

      const res = await fetch(url.toString(), {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal: getSignal?.(),
      })

      if (res.status === 403) return
      if (!res.ok) return

      const data = await res.json()
      const items = Array.isArray(data?.data) ? data.data : []
      if (items.length === 0) return

      if (isScrollNearTop()) onPrepend?.(items)
      else onPending?.(items)
    } catch (err) {
      if (err?.name === 'AbortError') return
      console.warn('Orders list live updates poll failed:', err)
    }
  }

  const background = useBackgroundPoll({
    intervalMs: pollIntervalMs,
    enabled,
    runOnMount: true,
    runOnVisible: true,
    tick: poll,
  })

  watch(
    () => unref(enabled),
    (on) => {
      if (on) background.start()
      else background.stop()
    },
  )

  return {
    poll: () => background.runNow(),
    startPolling: () => background.start(),
    stopPolling: () => background.stop(),
  }
}

/**
 * URL del poll conservando filtros activos (sin page / since_id).
 * @returns {string}
 */
export function buildOrdersListUpdatesUrl() {
  const u = new URL(window.location.href)
  const named = typeof route === 'function' ? route('orders.list-updates') : '/orders/list-updates'
  try {
    const namedUrl = new URL(named, window.location.origin)
    u.pathname = namedUrl.pathname
  } catch {
    u.pathname = '/orders/list-updates'
  }
  u.searchParams.delete('page')
  u.searchParams.delete('since_id')
  const qs = u.searchParams.toString()
  return qs ? `${u.pathname}?${qs}` : u.pathname
}
