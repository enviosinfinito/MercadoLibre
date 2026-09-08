/** Visual tokens for ActionBar surfaces and child components. */

/** Mínimo usable cuando env() es 0 (Android/gestos); iOS usa el inset real con viewport-fit=cover. */
export const SAFE_AREA_BOTTOM = 'pb-[max(1.25rem,env(safe-area-inset-bottom,0px))]'

/** BottomBar/layout-dock: padding simétrico; safe-area solo en móvil. */
export const LAYOUT_DOCK_PADDING =
  'py-1.5 sm:py-2 max-sm:pb-[max(0.5rem,env(safe-area-inset-bottom,0px))]'

export const DOCK_BASE =
  'relative z-10 w-full shrink-0 border-t border-neutral-200/90 bg-white/98 backdrop-blur-md shadow-[0_-4px_24px_rgba(0,0,0,0.07)]'

export const DENSITY = {
  compact: {
    gap: 'gap-2',
    paddingY: 'py-2',
    paddingX: 'px-4',
    buttonSize: 'sm',
  },
  comfortable: {
    gap: 'gap-3',
    paddingY: 'py-3 sm:py-4',
    paddingX: 'px-4 sm:px-6',
    buttonSize: 'md',
  },
}

export const ELEVATION = {
  none: '',
  sm: 'shadow-sm',
  elevatedUp: 'shadow-[0_-4px_24px_rgba(0,0,0,0.07)]',
  floating: 'shadow-[0_8px_32px_rgba(0,0,0,0.12)]',
}

export const BLUR = {
  none: '',
  sm: 'backdrop-blur-sm',
  md: 'backdrop-blur-md',
}

export const RECORD_ROW = {
  gap: 'gap-1 sm:gap-1.5',
  buttonSize: 'sm',
  align: 'end',
}

export const ACTION_BUTTON_SOFT_VARIANTS = {
  'success-soft':
    'border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 focus:ring-emerald-500/40',
  'warning-soft':
    'border border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100 focus:ring-amber-500/40',
  'danger-soft':
    'border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 focus:ring-red-500/40',
  'neutral-soft':
    'border border-neutral-200 bg-white text-neutral-700 hover:bg-neutral-50 focus:ring-gray-500',
}
