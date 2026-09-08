<template>
  <div :class="sectionClass">
    <slot />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useActionBarContext } from './useActionBarContext.js'

const props = defineProps({
  align: {
    type: String,
    default: 'start',
    validator: (v) => ['start', 'center', 'end'].includes(v),
  },
  hideOnMobile: {
    type: Boolean,
    default: false,
  },
  bordered: {
    type: Boolean,
    default: false,
  },
})

const context = useActionBarContext({})

const sectionClass = computed(() => {
  const alignMap = {
    start: 'justify-start',
    center: 'justify-center',
    end: 'justify-end',
  }

  const classes = [
    'action-bar-section flex min-w-0 flex-wrap items-center gap-2',
    alignMap[props.align],
  ]

  if (props.align === 'start') {
    classes.push('action-bar-section--start min-w-0 shrink-0')
  } else if (props.align === 'end') {
    classes.push(
      context.layoutMode === 'toolbar'
        ? 'action-bar-section--end min-w-0 shrink-0 justify-end'
        : 'action-bar-section--end min-w-0 flex-1 justify-end'
    )
  } else {
    classes.push('action-bar-section--center w-full min-w-0 flex-1')
  }

  if (props.hideOnMobile) {
    classes.push('max-lg:hidden lg:flex')
  }

  if (props.bordered) {
    classes.push('border-t border-neutral-200/40 px-1 pt-1 lg:border-0 lg:pt-0')
  }

  return classes.join(' ')
})
</script>
