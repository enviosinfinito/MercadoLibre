<template>
  <div
    role="separator"
    :aria-orientation="orientation"
    :class="dividerClass"
  />
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  orientation: {
    type: String,
    default: 'vertical',
    validator: (v) => ['vertical', 'horizontal'].includes(v),
  },
})

const dividerClass = computed(() =>
  props.orientation === 'horizontal'
    ? 'h-px w-full shrink-0 bg-neutral-200/90'
    : 'mx-1 hidden h-6 w-px shrink-0 self-center bg-neutral-200/90 sm:block'
)
</script>
