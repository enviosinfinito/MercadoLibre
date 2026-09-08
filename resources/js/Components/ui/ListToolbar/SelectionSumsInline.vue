<template>
  <div
    v-if="show"
    class="selection-sums-inline relative min-w-0"
  >
    <div
      ref="scrollRef"
      class="flex min-w-0 items-center gap-x-0 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      @scroll="onScroll"
    >
      <template v-if="loadingAllIds">
        <span
          v-for="(ph, i) in placeholders"
          :key="`ph-${i}`"
          class="shrink-0 whitespace-nowrap px-1.5 text-[11px] tabular-nums leading-none text-neutral-300"
          :style="{ minWidth: `${Math.max(String(ph).length, 6)}ch` }"
        >
          <span class="inline-block animate-pulse">···</span>
        </span>
      </template>
      <template v-else>
        <button
          v-for="(item, index) in sumItems"
          :key="item.key"
          type="button"
          class="shrink-0 whitespace-nowrap border-0 bg-transparent px-1.5 py-0 text-left text-[11px] leading-none tabular-nums text-neutral-500 transition-colors hover:text-neutral-800"
          :title="`Copiar ${item.formatted}`"
          @click="$emit('copy-sum', item)"
        >
          <span v-if="index > 0" class="mr-1.5 text-neutral-300" aria-hidden="true">·</span>
          <span class="text-neutral-400">{{ item.label }}</span>
          <span class="ml-1 font-medium text-neutral-700">{{ item.formatted }}</span>
        </button>
      </template>
    </div>
    <span
      v-if="showOverflowHint"
      class="pointer-events-none absolute inset-y-0 right-0 w-5 bg-gradient-to-l from-white to-transparent"
      aria-hidden="true"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps({
  sumItems: { type: Array, default: () => [] },
  sumLayoutPlaceholders: { type: Array, default: () => [] },
  loadingAllIds: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
})

defineEmits(['copy-sum'])

const scrollRef = ref(null)
const hasOverflow = ref(false)
const atEnd = ref(true)

const show = computed(
  () => props.loadingAllIds
    ? props.sumLayoutPlaceholders.length > 0
    : props.sumItems.length > 0,
)

const placeholders = computed(() =>
  props.sumLayoutPlaceholders.length
    ? props.sumLayoutPlaceholders
    : props.sumItems.map((s) => s.formatted || '$999,999.00'),
)

const showOverflowHint = computed(() => hasOverflow.value && !atEnd.value)

function measure() {
  const el = scrollRef.value
  if (!el) {
    hasOverflow.value = false
    atEnd.value = true
    return
  }
  hasOverflow.value = el.scrollWidth > el.clientWidth + 2
  atEnd.value = el.scrollLeft + el.clientWidth >= el.scrollWidth - 2
}

function onScroll() {
  measure()
}

let ro = null
onMounted(() => {
  measure()
  if (typeof ResizeObserver !== 'undefined' && scrollRef.value) {
    ro = new ResizeObserver(measure)
    ro.observe(scrollRef.value)
  }
})

onUnmounted(() => {
  ro?.disconnect()
})

watch(() => [props.sumItems, props.loadingAllIds, props.sumLayoutPlaceholders], () => {
  requestAnimationFrame(measure)
}, { deep: true })
</script>
