/**
 * Layout de Slider Windows (SlideOverPanel).
 *
 * Ancho desktop: CSS vars --slide-over-width / --slide-over-nested-step / --slide-over-min-width
 * (inyectadas desde config/layout.php → .env). Móvil (< md): siempre 100%.
 *
 * Excepciones de ancho vía prop maxWidth:
 * - QuickQuote (82vw)
 * - Detalle de envío (~25% más que el % estándar, desktop)
 * - Detalle Ads asistente (65% desktop)
 */

/** @deprecated Tiers sm|md|lg|xl ya no gobiernan el ancho; se aceptan por compat. */
export const SLIDE_OVER_SIZE_KEYS = ['sm', 'md', 'lg', 'xl']

/** Máximo de niveles con reducción progresiva de ancho. */
export const STACK_MAX_DEPTH = 3

/** Fallbacks si las CSS vars no están en :root. */
export const SLIDE_OVER_WIDTH_PERCENT_DEFAULT = 45
export const SLIDE_OVER_NESTED_STEP_PERCENT_DEFAULT = 5
export const SLIDE_OVER_MIN_WIDTH_PERCENT_DEFAULT = 30

/**
 * Detalle de envío (Index / SlideOver): ~25% más ancho que --slide-over-width en desktop.
 * Móvil sigue full-bleed vía mobileFullBleed del panel.
 */
export const SHIPMENT_DETAIL_SLIDE_OVER_WIDTH_CLASS =
  'max-w-none w-full md:w-[calc(var(--slide-over-width,45%)*1.25)] md:max-w-[min(100%,calc(var(--slide-over-width,45%)*1.25))]'

/** Detalle de orden (Index / SlideOver): mismo +25% que shipment detail. */
export const ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS = SHIPMENT_DETAIL_SLIDE_OVER_WIDTH_CLASS

/** Detalle Ads (asistente): ~65% del viewport en desktop para KPIs + gráfico. */
export const ADS_ENTITY_DETAIL_SLIDE_OVER_WIDTH_CLASS =
  'max-w-none w-full md:w-[65%] md:max-w-[65%]'

/** z-index base del slide-over (por encima de overlays móviles ~999998). */
export const SLIDE_OVER_Z_INDEX_BASE = 1000000

/** Calendario PrimeVue anidado en slide-over: por encima del apilado máximo (stack + backdrop). */
export const DATE_PICKER_PANEL_Z_INDEX = SLIDE_OVER_Z_INDEX_BASE + STACK_MAX_DEPTH * 10 + 100

/** Dropdowns/selects flotantes (SearchableSelect, etc.) dentro de slide-over. */
export const NESTED_FLOATING_UI_Z_INDEX = DATE_PICKER_PANEL_Z_INDEX

/** Toasts Notivue: por encima del slide-over apilado (Notivue lee --nv-z desde :root). */
export const NOTIVUE_Z_INDEX = SLIDE_OVER_Z_INDEX_BASE + STACK_MAX_DEPTH * 10 + 200

/** BaseModal / diálogos abiertos desde dentro de un SlideOver (por encima del panel). */
export const MODAL_OVER_SLIDE_OVER_Z_INDEX = SLIDE_OVER_Z_INDEX_BASE + STACK_MAX_DEPTH * 10 + 300

/** Por encima del apilado máximo de SlideOverPanel (companion + stack). */
export const GLOBAL_FLOATING_OVERLAY_Z_INDEX = 100100

/**
 * @deprecated Ya no se usan tiers rem. Conservado vacío para imports legacy.
 */
export const SLIDE_OVER_SIZES = {
  sm: { class: '' },
  md: { class: '' },
  lg: { class: '' },
  xl: { class: '' },
}

export function isSlideOverSize(value) {
  return SLIDE_OVER_SIZE_KEYS.includes(value)
}

/**
 * Clase de ancho. Solo maxWidth (p. ej. QuickQuote) tiene efecto;
 * el resto usa CSS unificado (slide-over-panel-content).
 */
export function resolveSlideOverWidthClass(size, maxWidthOverride) {
  if (maxWidthOverride) return maxWidthOverride
  return 'slide-over-width-standard'
}

/** @deprecated sizeClass ya no aplica --slide-base-max; no-op. */
export function resolveSlideOverSizeClass(_size) {
  return ''
}

export function resolveSlideOverZIndex(explicitZIndex, stackDepth) {
  if (explicitZIndex != null && explicitZIndex !== '') {
    const n = Number(explicitZIndex)
    return Number.isFinite(n) ? n : SLIDE_OVER_Z_INDEX_BASE + stackDepth * 10
  }
  return SLIDE_OVER_Z_INDEX_BASE + stackDepth * 10
}

/**
 * Ancho desktop para depth N: max(min%, base% - N * step%).
 * Lee CSS vars de :root; fallbacks 45 / 5 / 30.
 */
export function resolveSlideOverWidthPercent(stackDepth = 0) {
  const depth = Math.max(0, Math.min(Number(stackDepth) || 0, STACK_MAX_DEPTH))
  let base = SLIDE_OVER_WIDTH_PERCENT_DEFAULT
  let step = SLIDE_OVER_NESTED_STEP_PERCENT_DEFAULT
  let min = SLIDE_OVER_MIN_WIDTH_PERCENT_DEFAULT

  if (typeof document !== 'undefined') {
    const styles = getComputedStyle(document.documentElement)
    const readPct = (name, fallback) => {
      const raw = styles.getPropertyValue(name).trim()
      if (!raw) return fallback
      const n = parseFloat(raw)
      return Number.isFinite(n) ? n : fallback
    }
    base = readPct('--slide-over-width', base)
    step = readPct('--slide-over-nested-step', step)
    min = readPct('--slide-over-min-width', min)
  }

  if (min > base) min = base
  return Math.max(min, base - depth * step)
}

/** Tabs en header de SlideOverPanel: segmento pill en fila propia bajo el título. */
export const SLIDE_OVER_TABS_LIST_CLASS =
  'h-8 w-full min-w-0 justify-start gap-0.5 overflow-x-auto rounded-lg border-0 bg-neutral-100/90 p-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden'

export const SLIDE_OVER_TABS_TRIGGER_CLASS =
  'h-7 shrink-0 rounded-md border-0 px-2 text-[12px] font-medium text-neutral-500 shadow-none outline-none transition-colors hover:text-neutral-800 focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0 data-[state=active]:bg-white data-[state=active]:font-semibold data-[state=active]:text-brand data-[state=active]:shadow-sm sm:px-3 sm:text-[13px]'
