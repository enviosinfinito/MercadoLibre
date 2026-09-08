import { computed, nextTick, onBeforeUnmount, onMounted, ref, toValue, watch } from 'vue'
import { jsonFetch } from '@/lib/jsonFetch'

/**
 * Contenedor con overflow real del layout — NO window por defecto si hay panel interno.
 * Si no hay match, root = null (viewport / document scroll).
 *
 * @param {Element|null} el
 * @returns {Element|null}
 */
export function getListScrollRoot(el) {
  if (!el || typeof el.closest !== 'function') return null
  return el.closest('[data-content-safe-area]')
    || el.closest('.content-slot')
    || null
}

/**
 * Scroll infinito LengthAwarePaginator (Inertia page 1 + JSON page 2+).
 *
 * @param {Object} options
 * @param {import('vue').MaybeRefOrGetter<object|null|undefined>} options.initialPaginator
 * @param {string} [options.rootMargin='240px']
 * @param {(item: object) => string|number} [options.getItemId]
 */
export function useInfiniteList({
  initialPaginator,
  rootMargin = '240px',
  getItemId = (item) => item?.id,
} = {}) {
  const accumulatedItems = ref([])
  const lastLoadedPage = ref(null)
  const isLoading = ref(false)
  const isLoadingMore = ref(false)
  const loadMoreSentinel = ref(null)
  const metaOverride = ref(null)

  let intersectionObserver = null
  let loadMoreAbortController = null

  const basePaginator = computed(() => toValue(initialPaginator) ?? {})

  const displayItems = computed(() => {
    if (accumulatedItems.value.length > 0) {
      return accumulatedItems.value
    }
    return Array.isArray(basePaginator.value?.data) ? basePaginator.value.data : []
  })

  const effectiveMeta = computed(() => {
    const base = basePaginator.value
    const override = metaOverride.value
    return {
      current_page: lastLoadedPage.value
        ?? override?.current_page
        ?? base?.current_page
        ?? 1,
      last_page: override?.last_page ?? base?.last_page ?? 1,
      total: override?.total ?? base?.total ?? displayItems.value.length,
      from: override?.from ?? base?.from ?? (displayItems.value.length > 0 ? 1 : null),
      to: accumulatedItems.value.length > 0
        ? displayItems.value.length
        : (override?.to ?? base?.to ?? displayItems.value.length),
      per_page: override?.per_page ?? base?.per_page ?? 25,
    }
  })

  const hasMorePages = computed(() => {
    const { current_page: current, last_page: last } = effectiveMeta.value
    return Number(current) < Number(last)
  })

  const totalCount = computed(() => Number(effectiveMeta.value.total) || 0)

  const paginationFrom = computed(() => {
    if (accumulatedItems.value.length > 0) return displayItems.value.length > 0 ? 1 : null
    return effectiveMeta.value.from
  })

  const paginationTo = computed(() => {
    if (accumulatedItems.value.length > 0) return displayItems.value.length
    return effectiveMeta.value.to
  })

  function itemKey(item) {
    const id = getItemId(item)
    return id == null ? null : Number(id)
  }

  function abortLoadMore() {
    if (loadMoreAbortController) {
      loadMoreAbortController.abort()
      loadMoreAbortController = null
    }
  }

  function resetAccumulation() {
    abortLoadMore()
    isLoadingMore.value = false
    accumulatedItems.value = []
    lastLoadedPage.value = null
    metaOverride.value = null
  }

  function disconnectObserver() {
    if (intersectionObserver) {
      intersectionObserver.disconnect()
      intersectionObserver = null
    }
  }

  async function loadMore() {
    if (isLoadingMore.value || !hasMorePages.value || isLoading.value) return

    const currentPage = Number(effectiveMeta.value.current_page) || 1
    const nextPage = currentPage + 1

    abortLoadMore()
    loadMoreAbortController = new AbortController()
    const { signal } = loadMoreAbortController

    isLoadingMore.value = true
    try {
      const url = new URL(window.location.href)
      url.searchParams.set('page', String(nextPage))

      const data = await jsonFetch(url.toString(), {
        method: 'GET',
        signal,
      })

      if (signal.aborted) return

      const newRows = Array.isArray(data?.data) ? data.data : []
      metaOverride.value = {
        current_page: Number(data.current_page || nextPage),
        last_page: Number(data.last_page ?? effectiveMeta.value.last_page),
        total: Number(data.total ?? effectiveMeta.value.total),
        from: data.from ?? null,
        to: data.to ?? null,
        per_page: Number(data.per_page ?? effectiveMeta.value.per_page),
      }

      if (newRows.length > 0) {
        const base = accumulatedItems.value.length > 0
          ? accumulatedItems.value
          : (Array.isArray(basePaginator.value?.data) ? basePaginator.value.data : [])
        const existingIds = new Set(
          base.map((row) => itemKey(row)).filter((id) => id != null && !Number.isNaN(id)),
        )
        const unique = newRows.filter((row) => {
          const id = itemKey(row)
          if (id == null || Number.isNaN(id)) return true
          if (existingIds.has(id)) return false
          existingIds.add(id)
          return true
        })
        accumulatedItems.value = [...base, ...unique]
        lastLoadedPage.value = Number(data.current_page || nextPage)
      } else {
        lastLoadedPage.value = Number(data.current_page || nextPage)
      }
    } catch (e) {
      if (e?.name === 'AbortError') return
      console.error('[useInfiniteList] loadMore failed', e)
    } finally {
      if (!signal.aborted) {
        isLoadingMore.value = false
      }
    }
  }

  function setupInfiniteScroll() {
    const sentinel = loadMoreSentinel.value
    if (!sentinel || !hasMorePages.value || isLoadingMore.value || isLoading.value) {
      disconnectObserver()
      return
    }

    disconnectObserver()

    const scrollRoot = getListScrollRoot(sentinel)
    /** @type {IntersectionObserverInit} */
    const observerOptions = {
      rootMargin,
      threshold: 0,
    }
    // Solo fijar root si el contenedor tiene overflow propio; si no, viewport.
    if (scrollRoot) {
      const style = window.getComputedStyle(scrollRoot)
      const oy = style.overflowY
      const canScroll = oy === 'auto' || oy === 'scroll' || oy === 'overlay'
      if (canScroll) {
        observerOptions.root = scrollRoot
      }
    }

    intersectionObserver = new IntersectionObserver((entries) => {
      const entry = entries[0]
      if (
        entry?.isIntersecting
        && hasMorePages.value
        && !isLoadingMore.value
        && !isLoading.value
      ) {
        loadMore()
      }
    }, observerOptions)

    intersectionObserver.observe(sentinel)
  }

  function patchItem(id, patch) {
    const key = Number(id)
    const apply = (list) => list.map((row) => (
      itemKey(row) === key ? { ...row, ...patch } : row
    ))

    if (accumulatedItems.value.length > 0) {
      accumulatedItems.value = apply(accumulatedItems.value)
    }
  }

  function removeItems(ids) {
    const idSet = new Set((ids || []).map((id) => Number(id)))
    if (accumulatedItems.value.length > 0) {
      accumulatedItems.value = accumulatedItems.value.filter(
        (row) => !idSet.has(itemKey(row)),
      )
    }
  }

  watch(
    () => {
      const p = toValue(initialPaginator)
      return p?.data
    },
    () => {
      resetAccumulation()
    },
  )

  watch(
    [
      loadMoreSentinel,
      () => displayItems.value.length,
      hasMorePages,
      isLoading,
      isLoadingMore,
    ],
    () => {
      nextTick(() => setupInfiniteScroll())
    },
    { flush: 'post' },
  )

  onMounted(() => {
    nextTick(() => setupInfiniteScroll())
  })

  onBeforeUnmount(() => {
    disconnectObserver()
    abortLoadMore()
  })

  return {
    displayItems,
    hasMorePages,
    totalCount,
    paginationFrom,
    paginationTo,
    isLoading,
    isLoadingMore,
    loadMoreSentinel,
    lastLoadedPage,
    loadMore,
    resetAccumulation,
    setupInfiniteScroll,
    patchItem,
    removeItems,
    effectiveMeta,
  }
}
