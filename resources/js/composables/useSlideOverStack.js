import { computed, onBeforeUnmount, ref, watch } from 'vue'

/** Pila global FIFO de paneles laterales abiertos (orden de apertura). */
const openPanelIds = ref([])

/** id → { close: () => void } */
const panelHandlers = new Map()

let panelIdCounter = 0
let outsideCloseSuppressedUntil = 0
let cascadeClosing = false
let docListenerAttached = false

/** Delay entre cierres en cascada ≈ duración de slide-out (duration-300). */
export const SLIDE_OVER_CASCADE_STAGGER_MS = 300

/** true mientras corre un cierre en cascada (para congelar visuales). */
export const slideOverCascadeActive = ref(false)

function registerPanel(id) {
  if (openPanelIds.value.includes(id)) return
  openPanelIds.value = [...openPanelIds.value, id]
  ensureDocListener()
}

function unregisterPanel(id) {
  if (!openPanelIds.value.includes(id)) return
  openPanelIds.value = openPanelIds.value.filter((entry) => entry !== id)
  teardownDocListenerIfEmpty()
}

function getPanelDepth(id) {
  const index = openPanelIds.value.indexOf(id)
  return index === -1 ? 0 : index
}

function isTopmostPanel(id) {
  const ids = openPanelIds.value
  if (!ids.length) return true
  return ids[ids.length - 1] === id
}

/** Evita que un cierre del panel superior dispare "outside close" en los de abajo. */
export function suppressSlideOverOutsideClose(ms = 400) {
  outsideCloseSuppressedUntil = Date.now() + ms
}

export function isSlideOverOutsideCloseSuppressed() {
  return Date.now() < outsideCloseSuppressedUntil || cascadeClosing
}

export function getSlideOverStackSize() {
  return openPanelIds.value.length
}

/** Id del panel superior de la pila, o null si no hay slides abiertos. */
export function getTopmostSlideOverPanelId() {
  const ids = openPanelIds.value
  return ids.length ? ids[ids.length - 1] : null
}

/**
 * Snapshot reactivo de la pila (para anclar toasts / overlays al panel topmost).
 * @returns {import('vue').ComputedRef<{ size: number, topmostId: number|null, ids: number[] }>}
 */
export function useSlideOverStackSnapshot() {
  return computed(() => ({
    size: openPanelIds.value.length,
    topmostId: getTopmostSlideOverPanelId(),
    ids: [...openPanelIds.value],
  }))
}

/**
 * Registra el callback de cierre para cascadas (clic en panel inferior / fuera de todos).
 * @param {number} panelId
 * @param {{ close: () => void }} handlers
 */
export function registerSlideOverCloseHandler(panelId, handlers) {
  panelHandlers.set(panelId, handlers)
  return () => {
    if (panelHandlers.get(panelId) === handlers) {
      panelHandlers.delete(panelId)
    }
  }
}

function getPanelElement(panelId) {
  if (typeof document === 'undefined') return null
  return document.querySelector(`[data-slide-over-panel-id="${panelId}"]`)
}

function isIgnoredOutsideTarget(target) {
  if (!(target instanceof Element)) return true
  return Boolean(
    target.closest('[data-slide-over-nested-layer]') ||
      target.closest('[data-searchable-select-dropdown]') ||
      target.closest('[data-base-modal-portal]') ||
      target.closest('[data-sheet-backdrop-chrome]') ||
      target.closest('.shipment-filter-datepicker-panel') ||
      target.closest('[data-slide-over-floating-portal]')
  )
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Cierra paneles de arriba hacia abajo (topmost primero) con animación escalonada.
 * @param {number[]} idsTopFirst
 */
async function cascadeCloseIds(idsTopFirst) {
  if (!idsTopFirst.length || cascadeClosing) return
  cascadeClosing = true
  slideOverCascadeActive.value = true
  suppressSlideOverOutsideClose(idsTopFirst.length * SLIDE_OVER_CASCADE_STAGGER_MS + 500)
  try {
    for (let i = 0; i < idsTopFirst.length; i++) {
      const id = idsTopFirst[i]
      panelHandlers.get(id)?.close?.()
      if (i < idsTopFirst.length - 1) {
        await sleep(SLIDE_OVER_CASCADE_STAGGER_MS)
      }
    }
  } finally {
    // Esperar la última animación de salida antes de descongelar visuales
    await sleep(SLIDE_OVER_CASCADE_STAGGER_MS)
    cascadeClosing = false
    slideOverCascadeActive.value = false
  }
}

/** Cierra todos los paneles encima de `panelId` (no cierra el propio). Topmost primero. */
export function closeSlideOversAbove(panelId) {
  const ids = openPanelIds.value
  const idx = ids.indexOf(panelId)
  if (idx === -1 || idx >= ids.length - 1) return Promise.resolve()
  const above = ids.slice(idx + 1).reverse()
  return cascadeCloseIds(above)
}

/** Cierra toda la pila de arriba hacia abajo. */
export function closeAllSlideOversCascaded() {
  const ids = [...openPanelIds.value].reverse()
  return cascadeCloseIds(ids)
}

function onStackPointerDownCapture(event) {
  if (cascadeClosing || isSlideOverOutsideCloseSuppressed()) return
  if (event.button != null && event.button !== 0) return

  const target = event.target
  if (isIgnoredOutsideTarget(target)) return

  const ids = openPanelIds.value
  if (!ids.length) return

  // Índice del panel más alto (topmost) que contiene el clic
  let hitIndex = -1
  for (let i = ids.length - 1; i >= 0; i--) {
    const el = getPanelElement(ids[i])
    if (el && target instanceof Node && el.contains(target)) {
      hitIndex = i
      break
    }
  }

  // Clic dentro del topmost → interacción normal
  if (hitIndex === ids.length - 1) return

  // A: clic en un panel inferior (asoma) → cerrar los de arriba
  if (hitIndex >= 0) {
    event.preventDefault()
    event.stopPropagation()
    closeSlideOversAbove(ids[hitIndex])
    return
  }

  // B: clic fuera de todos → cerrar toda la pila (solo multi-nivel;
  //    un solo panel sigue el outside-close nativo de reka).
  if (ids.length <= 1) return

  event.preventDefault()
  event.stopPropagation()
  closeAllSlideOversCascaded()
}

function ensureDocListener() {
  if (docListenerAttached || typeof document === 'undefined') return
  document.addEventListener('pointerdown', onStackPointerDownCapture, true)
  docListenerAttached = true
}

function teardownDocListenerIfEmpty() {
  if (openPanelIds.value.length || !docListenerAttached || typeof document === 'undefined') return
  document.removeEventListener('pointerdown', onStackPointerDownCapture, true)
  docListenerAttached = false
}

/**
 * Registra un SlideOverPanel en la pila global para apilado visual y z-index.
 * @param {import('vue').MaybeRefOrGetter<boolean>} isOpen
 * @param {{ enabled?: import('vue').MaybeRefOrGetter<boolean> }} [options]
 */
export function useSlideOverStack(isOpen, options = {}) {
  const panelId = ++panelIdCounter
  const enabled = options.enabled ?? true

  const stackDepth = computed(() => {
    if (!resolveEnabled(enabled)) return 0
    return getPanelDepth(panelId)
  })

  const isTopmost = computed(() => {
    if (!resolveEnabled(enabled)) return true
    return isTopmostPanel(panelId)
  })

  const stackSize = computed(() => openPanelIds.value.length)

  watch(
    () => resolveOpen(isOpen) && resolveEnabled(enabled),
    (open) => {
      if (open) registerPanel(panelId)
      else unregisterPanel(panelId)
    },
    { immediate: true }
  )

  onBeforeUnmount(() => {
    unregisterPanel(panelId)
    panelHandlers.delete(panelId)
  })

  return { stackDepth, isTopmost, panelId, stackSize }
}

function resolveOpen(isOpen) {
  return typeof isOpen === 'function' ? isOpen() : isOpen?.value ?? isOpen
}

function resolveEnabled(enabled) {
  return typeof enabled === 'function' ? enabled() : enabled?.value ?? enabled
}

/** Solo para tests / depuración. */
export function resetSlideOverStackForTests() {
  openPanelIds.value = []
  panelHandlers.clear()
  cascadeClosing = false
  slideOverCascadeActive.value = false
  if (docListenerAttached && typeof document !== 'undefined') {
    document.removeEventListener('pointerdown', onStackPointerDownCapture, true)
    docListenerAttached = false
  }
}
