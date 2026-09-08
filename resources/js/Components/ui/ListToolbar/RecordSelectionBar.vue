<template>
  <div ref="anchorRef" class="record-selection-bar mb-1.5">
    <div
      v-if="hasSelection"
      class="flex min-h-7 items-center border-b border-neutral-200/50 px-0.5 py-1"
    >
      <ListBulkSelectionToolbar
        v-bind="bulkSelectionProps"
        @update:select-all="onSelectAllUpdate"
        @select-all-filtered="$emit('select-all-filtered')"
        @clear-selection="$emit('clear-selection')"
        @copy-sum="$emit('copy-sum', $event)"
      >
        <template v-if="$slots.actions" #actions>
          <slot name="actions" />
        </template>
      </ListBulkSelectionToolbar>
    </div>
  </div>

  <Teleport to="body">
    <div
      v-if="stickyMirrorMounted"
      class="pointer-events-none fixed right-0 z-40"
      :style="stickyFrameStyle"
    >
      <ListToolbarStickySelectionBar
        :visible="showStickyMirror"
        :bulk-selection-props="stickyProps"
        @update:select-all="onSelectAllUpdate"
        @select-all-filtered="$emit('select-all-filtered')"
        @clear-selection="$emit('clear-selection')"
        @leaved="onStickyLeaved"
      >
        <template v-if="$slots.actions" #more-actions>
          <div class="pointer-events-auto flex items-center gap-1 px-2">
            <slot name="actions" />
          </div>
        </template>
      </ListToolbarStickySelectionBar>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue'
import { useStickyWhenOutOfView } from '@/composables/useStickyWhenOutOfView.js'
import ListBulkSelectionToolbar from './ListBulkSelectionToolbar.vue'
import ListToolbarStickySelectionBar from './ListToolbarStickySelectionBar.vue'

const props = defineProps({
  hasSelection: { type: Boolean, default: false },
  bulkSelectionProps: { type: Object, default: () => ({}) },
  stickyWhenOutOfView: { type: Boolean, default: true },
})

const emit = defineEmits([
  'select-all-filtered',
  'clear-selection',
  'header-toggle',
  'copy-sum',
])

const anchorRef = ref(null)
const layoutHeaderHeight = inject('layoutHeaderHeight', ref(0))
const layoutSidebarWidth = inject('layoutSidebarWidth', ref(0))

const { showMirror: showStickyMirror } = useStickyWhenOutOfView({
  targetRef: anchorRef,
  enabled: computed(() => props.stickyWhenOutOfView),
  active: computed(() => props.hasSelection),
})

const stickyMirrorMounted = ref(false)
watch(showStickyMirror, (visible) => {
  if (visible) stickyMirrorMounted.value = true
}, { immediate: true })

function onStickyLeaved() {
  stickyMirrorMounted.value = false
}

const stickyFrameStyle = computed(() => ({
  top: `${layoutHeaderHeight.value}px`,
  left: `${layoutSidebarWidth.value}px`,
}))

const stickyProps = computed(() => ({
  ...props.bulkSelectionProps,
  checkboxIdMobile: 'selectAllMobileSticky',
  checkboxIdDesktop: 'selectAllDesktopSticky',
}))

function onSelectAllUpdate() {
  emit('header-toggle')
}
</script>
