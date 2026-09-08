import {
  BLUR,
  DENSITY,
  DOCK_BASE,
  ELEVATION,
  LAYOUT_DOCK_PADDING,
  RECORD_ROW,
  SAFE_AREA_BOTTOM,
} from './tokens.js'

/**
 * Context presets map to position + surface tokens.
 * position can be overridden via prop.
 */
export const PRESETS = {
  wizard: {
    position: 'dock',
    edge: 'bottom',
    density: 'comfortable',
    surface: {
      bg: DOCK_BASE.split(' ').filter((c) => c.startsWith('bg-')).join(' ') || 'bg-white/98',
      border: 'border-t border-neutral-200/90',
      blur: BLUR.md,
      shadow: ELEVATION.elevatedUp,
      rootExtra: ['page-content', 'mt-auto pt-2.5', SAFE_AREA_BOTTOM],
    },
  },
  form: {
    position: 'flow',
    edge: 'bottom',
    density: 'comfortable',
    surface: {
      bg: 'bg-transparent',
      border: 'border-t border-neutral-200/80',
      blur: BLUR.none,
      shadow: ELEVATION.none,
      rootExtra: ['mt-6 px-0 pt-4 sm:pt-5', SAFE_AREA_BOTTOM],
    },
  },
  slide: {
    position: 'sticky-bottom',
    edge: 'bottom',
    density: 'comfortable',
    surface: {
      bg: 'bg-white/95',
      border: 'border-t border-neutral-200/80',
      blur: BLUR.sm,
      shadow: ELEVATION.elevatedUp,
      rootExtra: ['layout-slot-padding py-3', SAFE_AREA_BOTTOM],
    },
  },
  modal: {
    position: 'modal',
    edge: 'bottom',
    density: 'compact',
    layoutMode: 'modal',
    surface: {
      bg: 'bg-gray-50',
      border: 'border-t border-gray-200',
      blur: BLUR.none,
      shadow: ELEVATION.none,
      rootExtra: ['px-4 py-3'],
    },
  },
  layout: {
    position: 'layout-dock',
    edge: 'bottom',
    density: 'compact',
    surface: {
      bg: 'bg-white/98',
      border: 'border-t border-neutral-200/90',
      blur: BLUR.md,
      shadow: ELEVATION.elevatedUp,
      rootExtra: ['layout-slot-padding', LAYOUT_DOCK_PADDING],
    },
  },
  toolbar: {
    position: 'inline',
    edge: 'top',
    density: 'compact',
    layoutMode: 'toolbar',
    surface: {
      bg: 'bg-transparent',
      border: 'border-none',
      blur: BLUR.none,
      shadow: ELEVATION.none,
      rootExtra: [],
    },
  },
  selection: {
    position: 'fixed-bottom',
    edge: 'bottom',
    density: 'compact',
    surface: {
      bg: 'bg-white/98',
      border: 'border-t border-neutral-200/90',
      blur: BLUR.md,
      shadow: ELEVATION.elevatedUp,
      rootExtra: ['layout-slot-padding px-4 py-3 z-[99980]', SAFE_AREA_BOTTOM],
    },
  },
  'bottom-sheet': {
    position: 'fixed-bottom',
    edge: 'bottom',
    density: 'comfortable',
    surface: {
      bg: 'bg-white',
      border: 'border-t border-neutral-200/80',
      blur: BLUR.none,
      shadow: ELEVATION.floating,
      rootExtra: ['rounded-t-2xl px-4 pt-4', SAFE_AREA_BOTTOM],
    },
  },
  'record-row': {
    position: null,
    edge: null,
    density: 'compact',
    group: RECORD_ROW,
  },
}

/** Map legacy PageActionFooter variant + pin to preset/position. */
export function resolveLegacyVariantPin(variant, pin) {
  const resolvedPin =
    pin ?? (variant === 'inline' || variant === 'modal' ? 'flow' : variant === 'embedded' || variant === 'layout' ? 'dock' : 'flow')

  const map = {
    embedded: resolvedPin === 'flow' ? { preset: 'form', position: 'flow' } : { preset: 'wizard', position: 'dock' },
    layout: { preset: 'layout', position: 'layout-dock' },
    sticky: { preset: 'slide', position: 'sticky-bottom' },
    modal: { preset: 'modal', position: 'modal' },
    inline: { preset: 'form', position: resolvedPin === 'flow' ? 'flow' : 'relative' },
  }

  return map[variant] ?? { preset: 'form', position: 'flow' }
}

export function getPreset(name) {
  return PRESETS[name] ?? null
}
