<template>
  <component
    :is="editorComponent"
    v-if="!wrapWithShell && editorComponent && isShown"
    :key="editorKey"
    v-bind="editorProps"
    class="min-h-0 flex-1"
    @close="onClose"
    @saved="onSaved"
  />
  <SlideOverShell
    v-else-if="wrapWithShell"
    :show="isShown"
    :title="title"
    :size="panelSize"
    :loading="isLoading"
    :error="loadError"
    body-class="bg-[#f5f5f7]"
    @close="onClose"
    @retry="onRetry"
  >
    <component
      :is="editorComponent"
      v-if="editorComponent && isShown && !isLoading && !loadError"
      :key="editorKey"
      v-bind="editorProps"
      class="min-h-0 flex-1"
      @close="onClose"
      @saved="onSaved"
    />
    <template #footer>
      <div :id="footerPortalId" class="slide-over-footer-portal" />
    </template>
  </SlideOverShell>
</template>

<script setup>
import { computed, provide, unref } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import { SLIDE_OVER_FOOTER_PORTAL_KEY } from '@/lib/slideOverFooterPortal'

const props = defineProps({
  slide: { type: Object, required: true },
})

const emit = defineEmits(['saved', 'close'])

const isShown = computed(() => Boolean(unref(props.slide.show)))
const isLoading = computed(() => Boolean(unref(props.slide.loading)))
const loadError = computed(() => unref(props.slide.error) ?? null)
const kind = computed(() => unref(props.slide.kind))
const record = computed(() => unref(props.slide.record))
const payload = computed(() => unref(props.slide.payload))

const panelSize = computed(() => unref(props.slide.definition)?.size ?? 'lg')

const wrapWithShell = computed(() => {
  const def = unref(props.slide.definition)
  if (def?.loadMode === 'fetch') return true
  return def?.wrapWithShell !== false
})

const title = computed(() => unref(props.slide.title) ?? 'Editor')

const editorComponent = computed(() => props.slide.resolveComponent?.() ?? unref(props.slide.definition)?.component ?? null)

const editorProps = computed(() => props.slide.mapEditorProps?.() ?? {})

const editorKey = computed(() => {
  const id = record.value?.configuration?.id
    ?? record.value?.id
    ?? payload.value?.configuration?.id
    ?? payload.value?.carrierAccount?.id
    ?? payload.value?.taxEntity?.id
    ?? 'new'
  return `${kind.value}-${id}`
})

const footerPortalId = computed(
  () => `index-crud-slide-footer-${kind.value ?? 'editor'}-${editorKey.value}`
)

provide(SLIDE_OVER_FOOTER_PORTAL_KEY, footerPortalId)

function onClose() {
  props.slide.close?.()
  emit('close')
}

function onRetry() {
  props.slide.retry?.()
}

function onSaved(data) {
  props.slide.handleSaved?.(data)
  emit('saved', { kind: kind.value, record: record.value, data })
}
</script>
