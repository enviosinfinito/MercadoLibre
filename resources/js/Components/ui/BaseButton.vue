<template>
  <component
    :is="tag"
    :type="tag === 'button' ? nativeType : undefined"
    :disabled="disabled || loading"
    :class="buttonClasses"
    v-bind="$attrs"
    @click="handleClick"
  >
    <span v-if="loading" class="inline-flex items-center justify-center shrink-0" aria-hidden="true">
      <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" :class="[iconOnly ? '' : 'mr-2', spinnerSizeClass]" />
    </span>
    <span v-if="iconOnly && !loading" class="inline-flex items-center justify-center">
      <slot />
    </span>
    <span v-else class="inline-flex items-center">
      <slot />
    </span>
  </component>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'primary',
    validator: (v) =>
      ['primary', 'secondary', 'danger', 'ghost', 'brand', 'outline', 'success', 'warning', 'text'].includes(v),
  },
  size: {
    type: String,
    default: 'md',
    validator: (v) => ['sm', 'md', 'lg'].includes(v),
  },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  type: { type: String, default: 'button' },
  as: { type: String, default: 'button', validator: (v) => ['button', 'a'].includes(v) },
  iconOnly: { type: Boolean, default: false },
})

const emit = defineEmits(['click'])

const tag = computed(() => (props.as === 'a' ? 'a' : 'button'))
const nativeType = computed(() => (tag.value === 'button' ? props.type : undefined))

const baseClasses =
  'inline-flex items-center justify-center font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 touch-manipulation disabled:opacity-50 disabled:cursor-not-allowed'

const variantClasses = {
  primary: 'bg-indigo-600 text-white hover:bg-indigo-700 focus:ring-indigo-500',
  brand: 'bg-brand text-white hover:bg-brand-hover focus:ring-brand/40',
  secondary: 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-gray-500',
  danger: 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
  ghost: 'text-gray-700 hover:bg-gray-100 focus:ring-gray-500',
  outline: 'border border-gray-300 bg-transparent text-gray-700 hover:bg-gray-50 focus:ring-gray-500',
  success: 'bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-emerald-500',
  warning: 'bg-amber-500 text-white hover:bg-amber-600 focus:ring-amber-500',
  text: 'bg-transparent text-gray-700 hover:bg-gray-100 focus:ring-gray-500 shadow-none',
}

const sizeClasses = {
  sm: 'px-3 py-1.5 text-sm',
  md: 'px-4 py-2 text-sm',
  lg: 'px-5 py-2.5 text-base',
}

const iconOnlySizeClasses = {
  sm: 'h-8 w-8 p-0',
  md: 'h-9 w-9 p-0',
  lg: 'h-10 w-10 p-0',
}

const spinnerSizeClass = {
  sm: 'text-sm',
  md: 'text-base',
  lg: 'text-lg',
}

const buttonClasses = computed(() => [
  baseClasses,
  variantClasses[props.variant],
  props.iconOnly ? iconOnlySizeClasses[props.size] : sizeClasses[props.size],
].filter(Boolean).join(' '))

function handleClick(e) {
  if (props.loading || props.disabled) return
  emit('click', e)
}
</script>
