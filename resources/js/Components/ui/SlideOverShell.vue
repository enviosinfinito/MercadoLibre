<template>
  <SlideOverPanel
    :show="show"
    :show-close-button="showCloseButton"
    :side="side"
    :size="size"
    :max-width="maxWidth ?? undefined"
    :close-only-header="closeOnlyHeader"
    :fill-height="fillHeight"
    :compact-header="compactHeader"
    :tabs-header="tabsHeader"
    :mobile-full-bleed="mobileFullBleed"
    :unsaved-changes="unsavedChanges"
    :unsaved-changes-message="unsavedChangesMessage"
    :stackable="stackable"
    :overlay-stack="overlayStack"
    :z-index="zIndex ?? undefined"
    :companion="companion"
    :light-backdrop="lightBackdrop"
    :modal="modal"
    :allow-outside-interact-close="allowOutsideInteractClose"
    :accessibility-title="accessibilityTitle || title"
    :accessibility-description="accessibilityDescription"
    @close="$emit('close')"
  >
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <template v-if="$slots['header-actions']" #header-actions>
      <slot name="header-actions" />
    </template>

    <div
      :class="[
        'flex min-h-0 flex-1 flex-col overflow-hidden',
        bodyClass,
      ]"
    >
      <SlideOverStates
        :loading="loading"
        :error="error ?? undefined"
        :loading-message="loadingMessage"
        :show-retry="showRetry"
        :content-class="statesContentClass"
        @retry="$emit('retry')"
      >
        <slot />
      </SlideOverStates>
    </div>

    <template v-if="$slots.footer" #footer>
      <slot name="footer" />
    </template>

    <template v-if="$slots.backdrop" #backdrop>
      <slot name="backdrop" />
    </template>
  </SlideOverPanel>
</template>

<script setup lang="ts">
import SlideOverPanel from '@/Components/ui/SlideOverPanel.vue'
import SlideOverStates from '@/Components/ui/SlideOverStates.vue'

withDefaults(
  defineProps<{
    show?: boolean
    title?: string
    accessibilityTitle?: string
    accessibilityDescription?: string
    /** @deprecated Ya no gobierna el ancho (modelo % unificado). */
    size?: string
    /** Override de ancho — QuickQuote (82vw) o detalle de envío (+25%). */
    maxWidth?: string | null
    side?: string
    showCloseButton?: boolean
    closeOnlyHeader?: boolean
    fillHeight?: boolean
    compactHeader?: boolean
    tabsHeader?: boolean
    mobileFullBleed?: boolean
    unsavedChanges?: boolean
    unsavedChangesMessage?: string
    stackable?: boolean
    /** Dim overlay al apilar; el ancho anidado (−step %) aplica siempre. */
    overlayStack?: boolean
    zIndex?: number | string | null
    companion?: boolean
    lightBackdrop?: boolean
    modal?: boolean
    allowOutsideInteractClose?: boolean
    loading?: boolean
    error?: string | null
    loadingMessage?: string
    showRetry?: boolean
    bodyClass?: string
    statesContentClass?: string
  }>(),
  {
    show: false,
    title: '',
    accessibilityTitle: '',
    accessibilityDescription: '',
    size: 'lg',
    maxWidth: null,
    side: 'right',
    showCloseButton: true,
    closeOnlyHeader: true,
    fillHeight: true,
    compactHeader: false,
    tabsHeader: false,
    mobileFullBleed: true,
    unsavedChanges: false,
    unsavedChangesMessage: 'Tienes cambios sin guardar.',
    stackable: true,
    overlayStack: true,
    zIndex: null,
    companion: false,
    lightBackdrop: true,
    modal: true,
    allowOutsideInteractClose: true,
    loading: false,
    error: null,
    loadingMessage: 'Cargando…',
    showRetry: true,
    bodyClass: 'bg-white',
    statesContentClass: 'flex min-h-0 flex-1 flex-col overflow-hidden',
  },
)

defineEmits<{ close: []; retry: [] }>()
</script>
