<template>
  <SlideOverShell
    :show="isShown"
    :title="title"
    :size="panelSize"
    :loading="isLoading"
    :error="loadError"
    :loading-message="loadingMessage"
    body-class="bg-[#f5f5f7]"
    @close="onClose"
    @retry="onRetry"
  >
    <component
      :is="editorComponent"
      v-if="editorComponent && hasPayload && !isLoading && !loadError"
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
import { getEmbeddedResource } from '@/lib/embeddedResourceRegistry'
import { SLIDE_OVER_FOOTER_PORTAL_KEY } from '@/lib/slideOverFooterPortal'

const props = defineProps({
  slide: { type: Object, required: true },
  loadingMessage: { type: String, default: 'Cargando…' },
})

const emit = defineEmits(['saved', 'close'])

const isShown = computed(() => Boolean(unref(props.slide.show)))
const isLoading = computed(() => Boolean(unref(props.slide.loading)))
const loadError = computed(() => unref(props.slide.error) ?? null)
const kind = computed(() => unref(props.slide.kind))
const resourceId = computed(() => unref(props.slide.resourceId))
const payload = computed(() => unref(props.slide.payload))

const definition = computed(() => getEmbeddedResource(kind.value))

const title = computed(() => unref(props.slide.title) ?? 'Detalle')

const panelSize = computed(() => definition.value?.size ?? 'lg')

const editorComponent = computed(() => definition.value?.component ?? null)

const hasPayload = computed(() => payload.value != null)

const editorProps = computed(() => props.slide.mapEditorProps?.() ?? {})

const editorKey = computed(() => {
  const id = resourceId.value
    ?? payload.value?.carrierAccount?.id
    ?? payload.value?.service?.id
    ?? payload.value?.configuration?.id
    ?? payload.value?.profile?.id
    ?? 'new'
  return `${kind.value}-${id}`
})

const footerPortalId = computed(
  () => `embedded-slide-footer-${kind.value ?? 'resource'}-${editorKey.value}`
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
  emit('saved', { kind: kind.value, id: resourceId.value, data })
}
</script>
