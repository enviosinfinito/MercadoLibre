<template>
  <div v-if="!isStickyMirror" ref="actionBarAnchorRef">
    <div :class="[rootClass, fallthroughClass]" role="toolbar" :aria-label="ariaLabel">
      <div :class="innerLayoutClass">
        <div v-if="showStartRow" :class="startRowClass">
          <ActionSection
            v-if="showStartSection"
            align="start"
            :hide-on-mobile="hideSectionOnMobile === 'start' || hideLeadingOnMobile"
            :class="layoutDockStartClass"
          >
            <slot name="start">
              <slot name="leading" />
            </slot>
          </ActionSection>

          <ActionSection
            v-if="showEndSection"
            align="end"
            :class="endSectionExtraClass"
            :aria-hidden="isLayoutDock && !hasEndContent"
          >
            <slot name="end">
              <slot name="actions">
                <ActionGroup v-if="hasDeclarativeActions && actionsLayout === 'grid'" layout="grid">
                  <ActionButton
                    v-for="(action, idx) in declarativeActions"
                    :key="idx"
                    v-bind="toActionButtonProps(action)"
                    @click="action.onClick && action.onClick($event)"
                  />
                </ActionGroup>
                <ActionGroup v-else-if="hasDeclarativeActions">
                  <ActionButton
                    v-for="(action, idx) in declarativeActions"
                    :key="idx"
                    v-bind="toActionButtonProps(action)"
                    :inner-class="declarativeButtonClass(action)"
                    @click="action.onClick && action.onClick($event)"
                  />
                </ActionGroup>
              </slot>
            </slot>
          </ActionSection>
        </div>

        <ActionSection
          v-if="showCenter"
          align="center"
          :bordered="centerBordered"
          :class="layoutDockCenterClass"
        >
          <slot name="center" />
        </ActionSection>
      </div>
    </div>
  </div>
  <div v-else :class="[rootClass, fallthroughClass]" role="toolbar" :aria-label="ariaLabel">
    <div :class="innerLayoutClass">
      <div v-if="showStartRow" :class="startRowClass">
        <ActionSection
          v-if="showStartSection"
          align="start"
          :hide-on-mobile="hideSectionOnMobile === 'start' || hideLeadingOnMobile"
          :class="layoutDockStartClass"
        >
          <slot name="start">
            <slot name="leading" />
          </slot>
        </ActionSection>

        <ActionSection
          v-if="showEndSection"
          align="end"
          :class="endSectionExtraClass"
          :aria-hidden="isLayoutDock && !hasEndContent"
        >
          <slot name="end">
            <slot name="actions">
              <ActionGroup v-if="hasDeclarativeActions && actionsLayout === 'grid'" layout="grid">
                <ActionButton
                  v-for="(action, idx) in declarativeActions"
                  :key="idx"
                  v-bind="toActionButtonProps(action)"
                  @click="action.onClick && action.onClick($event)"
                />
              </ActionGroup>
              <ActionGroup v-else-if="hasDeclarativeActions">
                <ActionButton
                  v-for="(action, idx) in declarativeActions"
                  :key="idx"
                  v-bind="toActionButtonProps(action)"
                  :inner-class="declarativeButtonClass(action)"
                  @click="action.onClick && action.onClick($event)"
                />
              </ActionGroup>
            </slot>
          </slot>
        </ActionSection>
      </div>

      <ActionSection
        v-if="showCenter"
        align="center"
        :bordered="centerBordered"
        :class="layoutDockCenterClass"
      >
        <slot name="center" />
      </ActionSection>
    </div>
  </div>

  <Teleport v-if="!isStickyMirror && stickyWhenOutOfView" to="body">
    <Transition name="action-bar-sticky-mirror">
      <div
        v-if="showStickyMirror"
        class="fixed right-0 z-30"
        :style="stickyMirrorFrameStyle"
      >
        <ActionBar
          v-bind="mirrorBindProps"
          is-sticky-mirror
          :sticky-when-out-of-view="false"
        >
          <template v-for="(_, slotName) in $slots" #[slotName]="slotProps">
            <slot :name="slotName" v-bind="slotProps || {}" />
          </template>
        </ActionBar>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, inject, ref, useAttrs, useSlots } from 'vue'
import ActionSection from './ActionSection.vue'
import ActionGroup from './ActionGroup.vue'
import ActionButton from './ActionButton.vue'
import ActionBar from './ActionBar.vue'
import { provideActionBarContext } from './useActionBarContext.js'
import { getPreset, resolveLegacyVariantPin } from './presets.js'
import { BLUR, DENSITY, DOCK_BASE, SAFE_AREA_BOTTOM } from './tokens.js'
import { useStickyWhenOutOfView } from '@/composables/useStickyWhenOutOfView.js'

defineOptions({ inheritAttrs: false })

const attrs = useAttrs()
const fallthroughClass = computed(() => attrs.class)

const props = defineProps({
  preset: {
    type: String,
    default: undefined,
  },
  position: {
    type: String,
    default: undefined,
  },
  edge: {
    type: String,
    default: 'bottom',
    validator: (v) => ['top', 'bottom'].includes(v),
  },
  density: {
    type: String,
    default: undefined,
    validator: (v) => v === undefined || ['compact', 'comfortable'].includes(v),
  },
  distribution: {
    type: String,
    default: 'auto',
    validator: (v) => ['auto', 'between', 'end', 'start', 'center'].includes(v),
  },
  hideSectionOnMobile: {
    type: String,
    default: undefined,
    validator: (v) => v === undefined || ['start', 'end'].includes(v),
  },
  variant: {
    type: String,
    default: undefined,
    validator: (v) =>
      v === undefined || ['embedded', 'layout', 'sticky', 'modal', 'inline'].includes(v),
  },
  pin: {
    type: String,
    default: undefined,
    validator: (v) => v === undefined || ['dock', 'flow'].includes(v),
  },
  align: {
    type: String,
    default: 'end',
    validator: (v) => ['end', 'between', 'start'].includes(v),
  },
  ariaLabel: {
    type: String,
    default: 'Acciones del formulario',
  },
  showInformativePages: {
    type: Boolean,
    default: false,
  },
  informativeLocation: {
    type: String,
    default: 'bottom_bar',
  },
  informativePages: {
    type: Array,
    default: undefined,
  },
  hideLeadingOnMobile: {
    type: Boolean,
    default: false,
  },
  primary: { type: Object, default: null },
  secondary: { type: Object, default: null },
  tertiary: { type: Object, default: null },
  actions: { type: Array, default: () => [] },
  actionsLayout: {
    type: String,
    default: 'row',
    validator: (v) => ['row', 'grid'].includes(v),
  },
  buttonSize: {
    type: String,
    default: 'md',
  },
  compact: { type: Boolean, default: false },
  background: { type: String, default: undefined },
  blur: { type: String, default: undefined },
  border: { type: String, default: undefined },
  shadow: { type: String, default: undefined },
  rounded: { type: String, default: undefined },
  fullWidth: { type: Boolean, default: true },
  stickyWhenOutOfView: { type: Boolean, default: false },
  stickyActive: { type: Boolean, default: true },
  isStickyMirror: { type: Boolean, default: false },
})

const slots = useSlots()
const actionBarAnchorRef = ref(null)
const layoutHeaderHeight = inject('layoutHeaderHeight', ref(0))
const layoutSidebarWidth = inject('layoutSidebarWidth', ref(0))

const { showMirror: showStickyMirror } = useStickyWhenOutOfView({
  targetRef: actionBarAnchorRef,
  enabled: computed(() => props.stickyWhenOutOfView && !props.isStickyMirror),
  active: computed(() => props.stickyActive),
})

const stickyMirrorFrameStyle = computed(() => ({
  top: `${layoutHeaderHeight.value}px`,
  left: `${layoutSidebarWidth.value}px`,
}))

const mirrorBindProps = computed(() => ({
  preset: props.preset,
  position: 'fixed-top',
  edge: props.edge,
  density: props.density,
  distribution: props.distribution,
  hideSectionOnMobile: props.hideSectionOnMobile,
  variant: props.variant,
  pin: props.pin,
  align: props.align,
  ariaLabel: props.ariaLabel,
  showInformativePages: props.showInformativePages,
  informativeLocation: props.informativeLocation,
  informativePages: props.informativePages,
  hideLeadingOnMobile: props.hideLeadingOnMobile,
  primary: props.primary,
  secondary: props.secondary,
  tertiary: props.tertiary,
  actions: props.actions,
  actionsLayout: props.actionsLayout,
  buttonSize: props.buttonSize,
  compact: props.compact,
  background: props.background,
  blur: props.blur,
  border: props.border,
  shadow: props.shadow,
  rounded: props.rounded,
  fullWidth: props.fullWidth,
  stickyActive: props.stickyActive,
}))

const resolvedFromLegacy = computed(() => {
  if (props.variant) {
    return resolveLegacyVariantPin(props.variant, props.pin)
  }
  return null
})

const resolvedPresetName = computed(() => props.preset ?? resolvedFromLegacy.value?.preset ?? 'form')

const presetConfig = computed(() => getPreset(resolvedPresetName.value) ?? getPreset('form'))

const resolvedPosition = computed(
  () => props.position ?? resolvedFromLegacy.value?.position ?? presetConfig.value?.position ?? 'flow'
)

const resolvedDensity = computed(() => {
  if (props.compact) return 'compact'
  return props.density ?? presetConfig.value?.density ?? 'comfortable'
})

const layoutMode = computed(() => presetConfig.value?.layoutMode ?? (resolvedPosition.value === 'modal' ? 'modal' : 'default'))

const isToolbarLayout = computed(() => layoutMode.value === 'toolbar')

const isEmbeddedLayout = computed(
  () => resolvedPosition.value === 'dock' || (props.variant === 'embedded' && !props.preset)
)

const isLayoutDock = computed(() => resolvedPosition.value === 'layout-dock')

const isFlowPin = computed(() => {
  if (props.pin === 'flow') return true
  if (props.pin === 'dock') return false
  return ['flow', 'relative'].includes(resolvedPosition.value)
})

const resolvedButtonSize = computed(() => DENSITY[resolvedDensity.value]?.buttonSize ?? props.buttonSize)

provideActionBarContext({
  preset: resolvedPresetName,
  density: resolvedDensity,
  buttonSize: resolvedButtonSize,
  layoutMode,
})

const showCenter = computed(() => !!slots.center)

const hasStartSection = computed(() => !!slots.start || !!slots.leading)
const hasEndSlot = computed(() => !!slots.end || !!slots.actions)

const declarativeActions = computed(() => {
  const list = []
  if (props.secondary) list.push(normalizeAction(props.secondary, 'secondary'))
  if (props.tertiary) list.push(normalizeAction(props.tertiary, 'ghost'))
  if (props.primary) list.push(normalizeAction(props.primary, 'brand'))
  if (props.actions?.length) {
    props.actions.forEach((a) => list.push(normalizeAction(a, a.variant || 'secondary')))
  }
  return list.filter(Boolean)
})

const hasDeclarativeActions = computed(() => declarativeActions.value.length > 0)
const hasEndContent = computed(() => hasEndSlot.value || hasDeclarativeActions.value)
const hasStart = computed(() => hasStartSection.value || hasEndContent.value)

/** layout-dock always keeps start/end grid cells (spacers) so center stays in the middle third. */
const showStartRow = computed(() => hasStart.value || isLayoutDock.value)
const showStartSection = computed(() => hasStartSection.value || isLayoutDock.value)
const showEndSection = computed(() => hasEndContent.value || isLayoutDock.value)

const centerBordered = computed(() => !isLayoutDock.value)

const layoutDockStartClass = computed(() => {
  if (!isLayoutDock.value) return ''
  return [
    'lg:col-start-1 lg:row-start-1 w-full min-w-0 !shrink self-center min-h-[1.125rem]',
    !hasStartSection.value ? 'max-lg:hidden' : '',
  ]
    .filter(Boolean)
    .join(' ')
})

const layoutDockCenterClass = computed(() => {
  if (!isLayoutDock.value) return 'lg:col-start-2 lg:row-start-1'
  return 'lg:col-start-2 lg:row-start-1 w-full min-w-0 self-center items-center min-h-[1.125rem] !flex-none'
})

const actionCount = computed(() => {
  if (slots.actions || slots.end) return 2
  return declarativeActions.value.length
})

function normalizeAction(action, defaultVariant) {
  if (!action) return null
  return {
    ...action,
    variant: action.variant || defaultVariant,
    inertia: action.inertia !== false,
  }
}

function toActionButtonProps(action) {
  return {
    label: action.loading && action.loadingLabel ? action.loadingLabel : action.label,
    loading: action.loading,
    disabled: action.disabled,
    variant: action.variant === 'brand' ? 'brand' : action.variant,
    size: action.size || resolvedButtonSize.value,
    type: action.type || 'button',
    form: action.form,
    href: action.href,
    inertia: action.inertia,
    appearance: action.appearance || 'default',
    badge: action.badge,
    badgeLoading: action.badgeLoading,
    icon: action.icon,
  }
}

function declarativeButtonClass(action) {
  const classes = ['min-h-[44px] action-bar-btn']
  if (layoutMode.value === 'modal' && actionCount.value >= 2) {
    classes.push('flex-1 sm:flex-initial')
  }
  if (isEmbeddedLayout.value || resolvedPresetName.value === 'layout') {
    classes.push('!rounded-2xl')
  } else {
    classes.push('!rounded-xl')
  }
  return classes.filter(Boolean).join(' ')
}

const innerLayoutClass = computed(() => {
  if (isToolbarLayout.value) {
    return 'flex min-w-0 items-stretch'
  }

  if (layoutMode.value === 'modal') {
    return [
      'flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3',
      props.align === 'between' && 'sm:justify-between',
      props.align !== 'between' && 'sm:justify-end',
    ]
      .filter(Boolean)
      .join(' ')
  }

  if (isEmbeddedLayout.value) {
    return 'flex min-w-0 w-full flex-col gap-1'
  }

  if (isLayoutDock.value) {
    return 'flex min-w-0 flex-col gap-0.5 lg:grid lg:grid-cols-3 lg:items-center lg:gap-x-4 lg:gap-y-0'
  }

  return 'flex min-w-0 flex-col gap-0.5 lg:grid lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-start lg:gap-x-4 lg:gap-y-0'
})

const startRowClass = computed(() => {
  if (isToolbarLayout.value) {
    return 'flex min-w-0 shrink-0 items-stretch'
  }

  if (layoutMode.value === 'modal') {
    return 'flex w-full min-w-0 flex-col gap-2 xs:flex-row xs:flex-wrap xs:items-stretch xs:gap-2'
  }
  if (isEmbeddedLayout.value) {
    return 'flex w-full min-w-0 flex-row flex-wrap items-center justify-between gap-x-3 gap-y-1'
  }
  return 'order-1 flex w-full min-w-0 flex-row flex-wrap items-center justify-between gap-x-2 gap-y-1 lg:contents'
})

const endSectionExtraClass = computed(() => {
  if (isToolbarLayout.value) {
    return 'flex min-w-0 shrink-0 items-stretch'
  }

  const classes = []
  if (layoutMode.value === 'modal') {
    classes.push('flex min-w-0 flex-1 flex-wrap items-stretch justify-end gap-2 sm:gap-3')
    if (actionCount.value >= 2) {
      classes.push(
        'max-xs:grid max-xs:grid-cols-2 max-xs:[&>button]:min-w-0 max-xs:[&>a]:min-w-0 max-xs:[&>.action-bar-btn]:min-w-0'
      )
    }
  } else if (isEmbeddedLayout.value) {
    classes.push('flex min-w-0 flex-1 flex-wrap items-center justify-end gap-2 sm:gap-3')
  } else if (isLayoutDock.value) {
    classes.push(
      'relative z-[2] flex w-full min-w-0 flex-wrap items-center justify-end gap-2 sm:gap-3',
      'lg:col-start-3 lg:row-start-1 lg:min-w-0 lg:justify-end self-center min-h-[1.125rem]',
      !hasEndContent.value ? 'max-lg:hidden' : ''
    )
  } else {
    classes.push(
      'relative z-[2] flex min-w-0 flex-1 flex-wrap items-center justify-end gap-2 sm:gap-3 lg:col-start-3 lg:row-start-1 lg:min-w-0 lg:justify-end lg:self-start'
    )
  }
  return classes.filter(Boolean).join(' ')
})

const rootClass = computed(() => {
  const preset = presetConfig.value
  const surface = preset?.surface ?? {}
  const densityTokens = DENSITY[resolvedDensity.value] ?? DENSITY.comfortable

  const classes = ['action-bar', `action-bar--${resolvedPresetName.value}`, 'shrink-0']

  if (props.fullWidth && !isToolbarLayout.value) {
    classes.push('w-full')
  } else {
    classes.push('w-auto min-w-0')
  }

  if (!props.fullWidth && !isToolbarLayout.value) {
    classes.push('max-w-screen-xl mx-auto')
  }

  switch (resolvedPosition.value) {
    case 'layout-dock':
      classes.push('action-bar--layout-dock', DOCK_BASE, ...(surface.rootExtra ?? []))
      break
    case 'sticky-bottom':
      classes.push(
        'action-bar--sticky-bottom sticky bottom-0 z-10',
        surface.border ?? 'border-t border-neutral-200/80',
        surface.bg ?? 'bg-white/95',
        surface.shadow ?? '',
        'layout-slot-padding',
        densityTokens.paddingY,
        surface.blur ?? BLUR.sm,
        SAFE_AREA_BOTTOM
      )
      break
    case 'fixed-bottom':
      classes.push(
        'action-bar--fixed-bottom fixed inset-x-0 bottom-0',
        surface.border ?? 'border-t border-neutral-200/90',
        surface.bg ?? 'bg-white/98',
        BLUR.md,
        ...(surface.rootExtra ?? ['layout-slot-padding px-4 py-3 z-[99980]', SAFE_AREA_BOTTOM])
      )
      break
    case 'fixed-top':
      classes.push(
        'action-bar--fixed-top w-full',
        'border-b border-neutral-200/80 bg-white/95 backdrop-blur-sm shadow-sm',
        'layout-slot-padding',
        densityTokens.paddingY,
        BLUR.sm
      )
      break
    case 'sticky-top':
      classes.push(
        'action-bar--sticky-top sticky top-0 z-10',
        'border-b border-neutral-200/80 bg-white/95 backdrop-blur-sm'
      )
      break
    case 'modal':
      classes.push(
        'action-bar--modal',
        surface.bg ?? 'bg-gray-50',
        surface.border ?? 'border-t border-gray-200',
        ...(surface.rootExtra ?? ['px-4 py-3'])
      )
      break
    case 'relative':
      if (!isToolbarLayout.value) {
        classes.push(
          'action-bar--relative shrink-0 border-t border-gray-200 bg-gray-50/95 px-6 py-4'
        )
      } else {
        classes.push('action-bar--inline')
      }
      break
    case 'inline':
      classes.push('action-bar--inline')
      break
    case 'flow':
      classes.push(
        'action-bar--flow shrink-0 w-full mt-6 border-t border-neutral-200/80 bg-transparent px-0 pt-4 sm:pt-5',
        SAFE_AREA_BOTTOM
      )
      break
    case 'floating':
      classes.push(
        'action-bar--floating fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-2xl border border-neutral-200/80 bg-white px-4 py-3 shadow-[0_8px_32px_rgba(0,0,0,0.12)]',
        SAFE_AREA_BOTTOM
      )
      break
    case 'dock':
    default:
      if (isFlowPin.value && resolvedPosition.value !== 'dock') {
        classes.push(
          'action-bar--flow shrink-0 w-full mt-6 border-t border-neutral-200/80 bg-transparent px-0 pt-4 sm:pt-5',
          SAFE_AREA_BOTTOM
        )
      } else {
        classes.push('action-bar--dock', DOCK_BASE, 'page-content mt-auto pt-2.5', SAFE_AREA_BOTTOM)
      }
      break
  }

  if (props.background) classes.push(props.background)
  if (props.border) classes.push(props.border)
  if (props.blur && props.blur !== 'none') classes.push(BLUR[props.blur] ?? props.blur)
  if (props.shadow) classes.push(props.shadow)
  if (props.rounded) classes.push(props.rounded)

  return classes.filter(Boolean).join(' ')
})
</script>

<style scoped>
.action-bar-sticky-mirror-enter-active,
.action-bar-sticky-mirror-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.action-bar-sticky-mirror-enter-from,
.action-bar-sticky-mirror-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>
