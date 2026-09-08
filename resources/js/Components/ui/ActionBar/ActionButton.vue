<template>
  <!-- Card / soft variants: custom element -->
  <component
    v-if="isCardAppearance || usesSoftVariant"
    :is="tag"
    :type="nativeType"
    :href="href || undefined"
    :form="tag === 'button' ? form : undefined"
    :disabled="isDisabled"
    :aria-label="iconOnly ? label : undefined"
    :aria-busy="loading || undefined"
    :title="title || (iconOnly ? label : undefined)"
    :class="buttonClass"
    v-bind="linkAttrs"
    @click="onClick"
  >
    <span class="flex min-w-0 items-center gap-1.5 sm:gap-2" :class="iconOnly ? 'justify-center' : 'flex-1'">
      <span v-if="loading" class="inline-flex shrink-0 self-center" aria-hidden="true">
        <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
      </span>
      <component v-else-if="iconComponent" :is="iconComponent" class="h-4 w-4 shrink-0" aria-hidden="true" />
      <i v-else-if="icon" :class="[icon, 'shrink-0 text-[10px]']" aria-hidden="true" />
      <span v-if="!iconOnly" class="min-w-0 truncate text-left">{{ displayLabel }}</span>
      <span v-else-if="showIconOnlyMobileLabel" class="whitespace-nowrap lg:hidden">{{ label }}</span>
    </span>
    <span
      v-if="isCardAppearance && badge"
      class="shrink-0 self-center whitespace-nowrap text-[10px] font-medium leading-none sm:text-[11px]"
      :class="badgeClass"
    >
      {{ loading && badgeLoading ? badgeLoading : badge }}
    </span>
  </component>

  <!-- Default: BaseButton -->
  <Link
    v-else-if="href && inertia"
    :href="href"
    preserve-scroll
    :class="[linkButtonClass, innerClass]"
    @click="onClick"
  >
    <i v-if="icon" :class="[icon, iconOnly ? '' : 'mr-1.5']" aria-hidden="true" />
    <template v-if="!iconOnly">{{ displayLabel }}</template>
  </Link>
  <BaseButton
    v-else
    :variant="baseVariant"
    :size="resolvedSize"
    :loading="loading"
    :disabled="disabled"
    :type="type"
    :as="href ? 'a' : 'button'"
    :href="href"
    :form="form"
    :icon-only="iconOnly"
    :class="innerClass"
    @click="onClick"
  >
    <i v-if="icon && !loading" :class="[icon, iconOnly ? '' : 'mr-1.5']" aria-hidden="true" />
    <template v-if="!iconOnly">{{ displayLabel }}</template>
  </BaseButton>
</template>

<script setup>
import { computed, unref } from 'vue'
import { Link } from '@inertiajs/vue3'
import BaseButton from '@/Components/ui/BaseButton.vue'
import { ACTION_BUTTON_SOFT_VARIANTS } from './tokens.js'
import { useActionBarContext } from './useActionBarContext.js'

const SOFT_VARIANTS = Object.keys(ACTION_BUTTON_SOFT_VARIANTS)

const props = defineProps({
  label: { type: String, default: '' },
  loadingLabel: { type: String, default: '' },
  badge: { type: String, default: '' },
  badgeLoading: { type: String, default: '…' },
  type: { type: String, default: 'button' },
  form: { type: String, default: undefined },
  href: { type: String, default: undefined },
  inertia: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  appearance: {
    type: String,
    default: 'default',
    validator: (v) => ['default', 'card', 'card-primary'].includes(v),
  },
  variant: {
    type: String,
    default: 'secondary',
  },
  size: { type: String, default: undefined },
  icon: { type: String, default: null },
  iconComponent: { type: [Object, Function], default: null },
  iconOnly: { type: Boolean, default: false },
  showIconOnlyMobileLabel: { type: Boolean, default: false },
  title: { type: String, default: null },
  innerClass: { type: String, default: '' },
})

const emit = defineEmits(['click'])

const context = useActionBarContext({})

const isCardAppearance = computed(() => ['card', 'card-primary'].includes(props.appearance))
const usesSoftVariant = computed(() => SOFT_VARIANTS.includes(props.variant))

const tag = computed(() => {
  if (props.href && props.inertia) return Link
  if (props.href) return 'a'
  return 'button'
})

const nativeType = computed(() => (tag.value === 'button' ? props.type : undefined))
const isDisabled = computed(() => props.disabled || props.loading)

const linkAttrs = computed(() => {
  if (props.href && props.inertia) {
    return { href: props.href, preserveScroll: true }
  }
  return {}
})

const displayLabel = computed(() =>
  props.loading && props.loadingLabel ? props.loadingLabel : props.label
)

const resolvedSize = computed(() => props.size || unref(context.buttonSize) || 'md')

const baseVariant = computed(() => {
  const v = props.variant
  if (['brand', 'primary', 'secondary', 'danger', 'ghost', 'outline', 'success', 'warning', 'text'].includes(v)) {
    return v
  }
  return 'secondary'
})

const badgeClass = computed(() =>
  props.appearance === 'card-primary' ? 'text-white/90' : 'text-neutral-500'
)

const cardBase =
  'box-border inline-flex min-w-0 items-center justify-between gap-1.5 transition disabled:cursor-not-allowed disabled:opacity-50 sm:gap-2 touch-manipulation action-bar-btn'

const softBase =
  'inline-flex shrink-0 items-center justify-center rounded-lg font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-2 touch-manipulation disabled:cursor-not-allowed disabled:opacity-50'

const buttonClass = computed(() => {
  if (props.appearance === 'card') {
    return [
      cardBase,
      'h-full min-h-[3.25rem] w-full rounded-2xl border border-neutral-200/80 bg-white px-2.5 py-2 text-[12px] font-semibold leading-snug text-brand shadow-[0_2px_16px_rgba(0,0,0,0.05)] hover:bg-neutral-50 sm:min-h-[3.5rem] sm:px-4 sm:py-2.5 sm:text-[13px]',
      props.innerClass,
    ].join(' ')
  }

  if (props.appearance === 'card-primary') {
    return [
      cardBase,
      'h-full min-h-[3.25rem] w-full rounded-2xl border border-brand bg-brand px-2.5 py-2 text-[12px] font-semibold leading-snug text-white shadow-[0_2px_16px_rgba(15,118,110,0.25)] hover:border-brand-hover hover:bg-brand-hover sm:min-h-[3.5rem] sm:px-4 sm:py-2.5 sm:text-[13px]',
      props.innerClass,
    ].join(' ')
  }

  const sizeClass = props.iconOnly ? 'h-8 w-8' : 'min-h-8 gap-1 px-2.5 py-1 text-xs'
  return [softBase, sizeClass, ACTION_BUTTON_SOFT_VARIANTS[props.variant], props.innerClass]
    .filter(Boolean)
    .join(' ')
})

const linkButtonClass = computed(() =>
  [
    'inline-flex items-center justify-center font-medium rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-50 min-h-[44px]',
  ].join(' ')
)

function onClick(e) {
  if (isDisabled.value) {
    e?.preventDefault?.()
    return
  }
  emit('click', e)
}
</script>
