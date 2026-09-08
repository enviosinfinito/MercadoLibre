<template>
  <div
    role="group"
    :aria-label="ariaLabel"
    :class="groupClass"
  >
    <slot />
  </div>
</template>

<script setup>
import { computed, unref } from 'vue'
import { useActionBarContext } from './useActionBarContext.js'
import { RECORD_ROW } from './tokens.js'

const props = defineProps({
  layout: {
    type: String,
    default: 'row',
    validator: (v) => ['row', 'grid'].includes(v),
  },
  stackOnMobile: {
    type: String,
    default: 'none',
    validator: (v) => ['none', 'reverse', 'primary-first'].includes(v),
  },
  preset: {
    type: String,
    default: null,
  },
  ariaLabel: {
    type: String,
    default: undefined,
  },
  align: {
    type: String,
    default: 'end',
    validator: (v) => ['start', 'center', 'end', 'stretch'].includes(v),
  },
})

const context = useActionBarContext({})

const isRecordRow = computed(() => props.preset === 'record-row' || unref(context.preset) === 'record-row')

const groupClass = computed(() => {
  const alignMap = {
    start: 'justify-start',
    center: 'justify-center',
    end: 'justify-end',
    stretch: 'justify-stretch',
  }

  const gap = isRecordRow.value ? RECORD_ROW.gap : 'gap-2 sm:gap-3'

  const base = [
    'flex min-w-0 flex-wrap items-center',
    gap,
    alignMap[props.align] ?? 'justify-end',
  ]

  if (props.layout === 'grid') {
    return [
      'grid w-full min-h-0 min-w-0 flex-1 grid-cols-[repeat(2,minmax(0,1fr))] content-stretch items-stretch gap-2 sm:gap-3',
      '[&>button]:min-h-0 [&>button]:min-w-0 [&>a]:min-h-0 [&>a]:min-w-0',
    ].join(' ')
  }

  if (props.stackOnMobile === 'reverse') {
    base.push('max-sm:flex-col-reverse max-sm:items-stretch')
  } else if (props.stackOnMobile === 'primary-first') {
    base.push('max-sm:flex-col max-sm:items-stretch')
  }

  if (isRecordRow.value) {
    base.push('flex-nowrap sm:flex-wrap')
  }

  return base.join(' ')
})
</script>
