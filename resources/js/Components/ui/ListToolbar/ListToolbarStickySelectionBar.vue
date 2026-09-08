<template>
  <div
    role="toolbar"
    aria-label="Acciones de selección"
    class="list-toolbar-sticky-mirror pointer-events-none"
  >
    <!-- Móvil: barra única flotante arriba -->
    <div v-if="!isDesktop" class="layout-slot-padding px-3 pt-2 pb-1">
      <Transition
        name="list-toolbar-sticky-mobile"
        appear
        @after-leave="onPartAfterLeave"
      >
        <div
          v-if="visible"
          key="mobile-bar"
          class="list-toolbar-sticky-float flex h-10 w-full items-stretch gap-1.5 p-1.5"
        >
          <div
            v-if="$slots['more-actions-trigger']"
            class="flex min-w-0 flex-[3] items-stretch gap-px overflow-hidden rounded-xl border border-neutral-200/70 bg-neutral-50/60 p-0.5"
          >
            <div class="relative min-w-0 flex-1">
              <slot name="more-actions-trigger" />
            </div>
          </div>
          <div
            class="flex min-w-0 flex-[2] items-center overflow-hidden rounded-xl border border-neutral-200/70 bg-neutral-50/40 px-2"
          >
            <slot name="selection">
              <ListBulkSelectionToolbar
                v-bind="bulkSelectionProps"
                @update:select-all="$emit('update:selectAll', $event)"
                @select-all-filtered="$emit('select-all-filtered')"
                @clear-selection="$emit('clear-selection')"
              />
            </slot>
          </div>
        </div>
      </Transition>
    </div>

    <!-- Escritorio: dos islas flotantes separadas -->
    <div v-else class="layout-slot-padding px-4 pt-2.5 pb-1">
      <div class="flex items-start justify-between gap-8">
        <Transition
          name="list-toolbar-sticky-from-left"
          appear
          @after-leave="onPartAfterLeave"
        >
          <div
            v-if="visible"
            key="selection"
            class="list-toolbar-sticky-float list-toolbar-sticky-float--desktop min-w-0 max-w-[min(100%,42rem)] shrink"
          >
            <div class="flex min-h-8 items-center bg-white/95 px-2.5 py-0.5 backdrop-blur-sm">
              <slot name="selection">
                <ListBulkSelectionToolbar
                  v-bind="bulkSelectionProps"
                  @update:select-all="$emit('update:selectAll', $event)"
                  @select-all-filtered="$emit('select-all-filtered')"
                  @clear-selection="$emit('clear-selection')"
                />
              </slot>
            </div>
          </div>
        </Transition>

        <Transition
          v-if="$slots['more-actions']"
          name="list-toolbar-sticky-from-right"
          appear
          @after-leave="onPartAfterLeave"
        >
          <div
            v-if="visible"
            key="actions"
            class="list-toolbar-sticky-float list-toolbar-sticky-float--desktop shrink-0"
          >
            <div class="flex h-9 min-h-9 items-stretch">
              <slot name="more-actions" />
            </div>
          </div>
        </Transition>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, useSlots, watch } from 'vue'
import ListBulkSelectionToolbar from './ListBulkSelectionToolbar.vue'

const DESKTOP_MEDIA = '(min-width: 768px)'

const props = defineProps({
  visible: { type: Boolean, default: true },
  bulkSelectionProps: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:selectAll', 'select-all-filtered', 'clear-selection', 'leaved'])

const slots = useSlots()
const isDesktop = ref(true)
let desktopMediaQuery = null

let leavePartsCompleted = 0

function expectedLeaveParts() {
  if (isDesktop.value) {
    return slots['more-actions'] ? 2 : 1
  }
  return 1
}

function onPartAfterLeave() {
  if (props.visible) return

  leavePartsCompleted += 1
  if (leavePartsCompleted >= expectedLeaveParts()) {
    leavePartsCompleted = 0
    emit('leaved')
  }
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      leavePartsCompleted = 0
    }
  }
)

function syncDesktopMatch(event) {
  isDesktop.value = event ? event.matches : desktopMediaQuery?.matches ?? true
}

onMounted(() => {
  if (typeof window === 'undefined') return
  desktopMediaQuery = window.matchMedia(DESKTOP_MEDIA)
  syncDesktopMatch(desktopMediaQuery)
  desktopMediaQuery.addEventListener('change', syncDesktopMatch)
})

onUnmounted(() => {
  desktopMediaQuery?.removeEventListener('change', syncDesktopMatch)
})
</script>

<style scoped>
.list-toolbar-sticky-from-left-enter-active {
  transition:
    opacity 0.3s ease,
    transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
}

.list-toolbar-sticky-from-left-leave-active,
.list-toolbar-sticky-from-right-leave-active {
  transition:
    opacity 0.24s ease-in,
    transform 0.26s cubic-bezier(0.4, 0, 1, 1);
}

.list-toolbar-sticky-from-right-enter-active {
  transition:
    opacity 0.3s ease,
    transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
}

.list-toolbar-sticky-from-left-enter-from,
.list-toolbar-sticky-from-left-leave-to {
  opacity: 0;
  transform: translateX(-28px);
}

.list-toolbar-sticky-from-right-enter-from,
.list-toolbar-sticky-from-right-leave-to {
  opacity: 0;
  transform: translateX(28px);
}

.list-toolbar-sticky-mobile-enter-active {
  transition:
    opacity 0.24s ease,
    transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}

.list-toolbar-sticky-mobile-leave-active {
  transition:
    opacity 0.2s ease-in,
    transform 0.22s cubic-bezier(0.4, 0, 1, 1);
}

.list-toolbar-sticky-mobile-enter-from,
.list-toolbar-sticky-mobile-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

@media (prefers-reduced-motion: reduce) {
  .list-toolbar-sticky-from-left-enter-active,
  .list-toolbar-sticky-from-left-leave-active,
  .list-toolbar-sticky-from-right-enter-active,
  .list-toolbar-sticky-from-right-leave-active,
  .list-toolbar-sticky-mobile-enter-active,
  .list-toolbar-sticky-mobile-leave-active {
    transition: opacity 0.15s ease;
  }

  .list-toolbar-sticky-from-left-enter-from,
  .list-toolbar-sticky-from-left-leave-to,
  .list-toolbar-sticky-from-right-enter-from,
  .list-toolbar-sticky-from-right-leave-to,
  .list-toolbar-sticky-mobile-enter-from,
  .list-toolbar-sticky-mobile-leave-to {
    transform: none;
  }
}
</style>
