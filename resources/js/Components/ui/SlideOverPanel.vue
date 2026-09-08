<template>
  <Sheet
    :open="show"
    :modal="effectiveModal"
    @update:open="onOpenChange"
  >
    <SheetContent
      :side="side"
      :z-index="resolvedZIndex"
      :light-backdrop="effectiveLightBackdrop"
      :allow-outside-close="effectiveAllowOutsideInteractClose"
      :accessibility-title="accessibilityTitle"
      :accessibility-description="accessibilityDescription"
      :class="sheetContentClass"
      :data-stack-depth="widthStackDepth"
      :data-slide-over-panel-id="panelId"
      :data-slide-over-nested-layer="companion ? 'true' : undefined"
      :data-slide-over-floating-portal-root="show ? 'true' : undefined"
      :data-panel-side="side"
      :data-companion-panel="companion ? 'true' : undefined"
      :data-slide-buried="isBuriedUnderStack ? 'true' : undefined"
      :data-slide-edge-shadow="edgeShadowKind"
      :style="panelWidthStyle"
    >
      <!--
        Cierre explícito (no DialogClose de reka) + X en la misma fila que el header (flex),
        para que quede arriba a la derecha del panel sin depender de absolute (evita que
        quede “arriba a la izquierda” si el contenedor del portal fuerza otro contexto).
      -->
      <div
        v-if="$slots.header || $slots['header-actions'] || showCloseButton"
        :class="headerBarClass"
      >
        <div v-if="$slots.header" class="min-w-0 flex-1">
          <slot name="header" />
        </div>
        <div
          v-if="$slots['header-actions'] || showCloseButton"
          class="flex shrink-0 items-center gap-0.5 self-start"
        >
          <slot name="header-actions" />
          <button
            v-if="showCloseButton"
            type="button"
            :class="closeButtonClass"
            aria-label="Cerrar panel"
            @click="requestClose"
          >
            <X class="h-4 w-4" :stroke-width="2" />
          </button>
        </div>
      </div>
      <!-- Aviso de cambios sin guardar -->
      <div
        v-if="showCloseConfirm"
        class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-amber-200/90 bg-amber-50/95 px-4 py-3 backdrop-blur-sm"
      >
        <p class="flex items-center gap-2 text-sm font-medium text-amber-950">
          <AlertTriangle class="h-4 w-4 shrink-0 text-amber-600" :stroke-width="2" aria-hidden="true" />
          {{ unsavedChangesMessage }}
        </p>
        <div class="flex items-center gap-2">
          <button
            type="button"
            @click="showCloseConfirm = false"
            class="rounded-full border border-neutral-200 bg-white px-4 py-2 text-sm font-medium text-neutral-800 shadow-sm transition-colors hover:bg-neutral-50"
          >
            Cancelar
          </button>
          <button
            type="button"
            @click="confirmCloseAnyway"
            class="rounded-full bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-amber-700"
          >
            Cerrar sin guardar
          </button>
        </div>
      </div>
      <!-- Contenido scrollable (safe-area inferior en móvil) -->
      <div
        :class="[
          'slide-over-content flex min-h-0 flex-1 flex-col overscroll-contain pb-[env(safe-area-inset-bottom,0px)]',
          fillHeight ? 'overflow-hidden' : 'overflow-auto',
        ]"
      >
        <slot />
      </div>
      <!-- Footer (sin ActionBar: evita ciclo ActionBar → InformativePagesFooterLinks → SlideOverPanel) -->
      <div
        v-if="$slots.footer"
        class="slide-over-footer shrink-0 px-0 py-0 pb-[max(0px,env(safe-area-inset-bottom,0px))]"
      >
        <slot name="footer" />
      </div>
      <div
        data-slide-over-floating-portal
        class="pointer-events-none fixed inset-0 z-[1000120]"
        aria-hidden="true"
      />
    </SheetContent>
  </Sheet>
  <!--
    reka-ui no renderiza DialogOverlay cuando modal=false (ver DialogOverlay.js).
    Con lightBackdrop + non-modal, duplicamos el velo de BaseModal debajo del panel.
  -->
  <Teleport to="body">
    <Transition
      enter-active-class="transition ease-out duration-200"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition ease-in duration-150"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="show && showManualBackdrop"
        data-slide-over-backdrop
        class="pointer-events-auto fixed inset-0 max-md:max-h-[100dvh] max-md:h-[100dvh] backdrop-blur-sm"
        :class="isStackedOverlay ? 'bg-black/30' : 'bg-black/20'"
        :style="{ zIndex: manualBackdropZIndex }"
        aria-hidden="true"
        @pointerdown.stop="onManualBackdropPointerDown"
      />
    </Transition>
  <!-- Slot backdrop: contenido flotante al lado del panel (p. ej. chrome QuickQuote) -->
    <div
      v-if="show && $slots.backdrop"
      class="fixed inset-0 pointer-events-none"
      :style="{ zIndex: backdropZIndex }"
    >
      <div class="pointer-events-auto" data-sheet-backdrop-chrome>
        <slot name="backdrop" />
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch, computed, useSlots, onBeforeUnmount } from 'vue'
import { AlertTriangle, X } from 'lucide-vue-next'
import { Sheet, SheetContent } from '@/Components/ui/sheet'
import {
  useSlideOverStack,
  suppressSlideOverOutsideClose,
  isSlideOverOutsideCloseSuppressed,
  registerSlideOverCloseHandler,
  closeAllSlideOversCascaded,
  getSlideOverStackSize,
  slideOverCascadeActive,
} from '@/composables/useSlideOverStack'
import {
  isSlideOverSize,
  resolveSlideOverWidthClass,
  resolveSlideOverWidthPercent,
  resolveSlideOverZIndex,
  SLIDE_OVER_Z_INDEX_BASE,
  STACK_MAX_DEPTH,
} from '@/lib/slideOverLayout'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  show: Boolean,
  unsavedChanges: {
    type: Boolean,
    default: false
  },
  unsavedChangesMessage: {
    type: String,
    default: 'Hay cambios sin guardar. ¿Cerrar de todos modos?'
  },
  side: {
    type: String,
    default: 'right',
    validator: (v) => ['left', 'right'].includes(v)
  },
  /**
   * @deprecated Ya no gobierna el ancho (modelo % unificado). Se acepta por compat.
   */
  size: {
    type: String,
    default: 'md',
    validator: (v) => isSlideOverSize(v),
  },
  /** Override de clases Tailwind — solo QuickQuote (82vw). Resto: ancho % del sistema. */
  maxWidth: {
    type: String,
    default: null,
  },
  zIndex: {
    type: [Number, String],
    default: null,
  },
  /** Participa en apilado visual (reducción de ancho % y z-index automático). */
  stackable: {
    type: Boolean,
    default: true
  },
  showCloseButton: {
    type: Boolean,
    default: true
  },
  /** true: velo ligero + blur (alineado con Step2 / asistente de seguro). false: velo oscuro. */
  lightBackdrop: {
    type: Boolean,
    default: true
  },
  /** false: permite foco/clics en otros portales (p. ej. BaseModal sobre el Sheet). true por defecto (acceso estándar). */
  modal: {
    type: Boolean,
    default: true
  },
  /**
   * Móvil/tablet estrecho: panel a todo el ancho y sin esquinas redondeadas.
   * Alineado con breakpoint md (768px). Default true (contrato unificado).
   */
  mobileFullBleed: {
    type: Boolean,
    default: true
  },
  /** Menos padding en la barra superior (formularios densos, p. ej. factura comercial). */
  compactHeader: {
    type: Boolean,
    default: false
  },
  /** Solo botón cerrar arriba a la derecha. El slot #header, si existe, es para tabs/controles — nunca títulos decorativos. */
  closeOnlyHeader: {
    type: Boolean,
    default: false
  },
  /** Header con tabs: sin borde inferior en la barra (el segmento activo ya se destaca). */
  tabsHeader: {
    type: Boolean,
    default: false,
  },
  /** El slot principal ocupa el alto restante; el scroll queda en hijos (tablas, listas). */
  fillHeight: {
    type: Boolean,
    default: false
  },
  accessibilityTitle: {
    type: String,
    default: 'Panel lateral',
  },
  accessibilityDescription: {
    type: String,
    default: 'Panel lateral con opciones y controles.',
  },
  /**
   * Panel compañero (p. ej. asistente IA): sin backdrop, no bloquea el panel principal.
   * Mismo modelo de ancho % que el resto (no companionWidthVw).
   */
  companion: {
    type: Boolean,
    default: false,
  },
  /**
   * Si false, no cierra al interactuar fuera aunque el panel esté arriba del stack.
   * SlideOverShell lo pasa; el apilado puede seguir bloqueando el cierre en paneles no topmost.
   */
  allowOutsideInteractClose: {
    type: Boolean,
    default: true,
  },
  /**
   * @deprecated Ignorado. El ancho unificado (%) reemplaza companionWidthVw.
   */
  companionWidthVw: {
    type: Number,
    default: null,
  },
  /**
   * @deprecated El dim/blur anidado ya no se usa: los sub-sliders no añaden velo.
   * Se conserva por compat; el ancho anidado (−step %) aplica siempre.
   */
  overlayStack: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['close'])
const slots = useSlots()
const hasHeaderSlot = computed(() => Boolean(slots.header))

/** Congela modal=false durante la animación de salida (evita flash de DialogOverlay). */
const leaveAsNonModal = ref(false)

/**
 * z-index al iniciar cierre: el panel se desregistra de la pila al instante,
 * pero el slide-out dura ~300ms. Sin freeze, nested y root quedan en el mismo
 * z-index → pelean y el nivel 1 parpadea.
 */
const leaveZIndexFreeze = ref(null)

/**
 * Ancho (stack depth) al iniciar cierre: sin esto, al desregistrar depth→0
 * y el panel salta al % base antes del slide-out (el “parpadeo” de ancho).
 */
const leaveWidthDepthFreeze = ref(null)

/** Snapshot visual mientras dura la cascada (evita saltos de sombra/top en paneles que quedan). */
const cascadeVisualFreeze = ref(null)

const effectiveLightBackdrop = computed(() => (props.companion ? false : props.lightBackdrop))
const effectiveStackable = computed(() => (props.companion ? false : props.stackable))

const { stackDepth, isTopmost, panelId, stackSize } = useSlideOverStack(
  () => props.show,
  { enabled: () => effectiveStackable.value }
)

/**
 * Una sola capa: outside nativo de reka.
 * Varias capas: el listener global cierra en cascada (clic en inferior / fuera de todos).
 */
const effectiveAllowOutsideInteractClose = computed(() => {
  if (props.companion) return false
  if (!props.allowOutsideInteractClose) return false
  if (!effectiveStackable.value) return true
  if (stackSize.value > 1) return false
  return isTopmost.value
})

const effectiveStackDepth = computed(() => {
  if (!props.stackable || props.companion) return 0
  return Math.min(stackDepth.value, STACK_MAX_DEPTH)
})

/**
 * Modal de Reka (DialogOverlay):
 * - companion / leaveAsNonModal / nested: nunca
 * - lightBackdrop: nunca — el velo es manual y estable en depth 0
 *   (evita parpadeo al reafirmar DialogOverlay cuando cierra la pila)
 */
const effectiveModal = computed(() => {
  if (props.companion) return false
  if (leaveAsNonModal.value) return false
  if (props.lightBackdrop) return false
  if (effectiveStackDepth.value > 0) return false
  return props.modal
})

/**
 * Profundidad para ancho %: siempre reduce en desktop al apilar.
 * maxWidth (QuickQuote) y companion no reducen por stack.
 * leaveWidthDepthFreeze: mantiene el % reducido durante el slide-out.
 */
const widthStackDepth = computed(() => {
  if (props.maxWidth || props.companion || !effectiveStackable.value) return 0
  if (leaveWidthDepthFreeze.value != null && (!props.show || leaveAsNonModal.value)) {
    return leaveWidthDepthFreeze.value
  }
  if (cascadeVisualFreeze.value) return cascadeVisualFreeze.value.widthDepth
  return effectiveStackDepth.value
})

/** Panel apilado encima de otro (z-index / sombra superior; sin velo/blur nuevo). */
const isStackedOverlay = computed(() => {
  return effectiveStackable.value && effectiveStackDepth.value > 0
})

/** Cualquier panel tapado por otro (raíz o intermedio): necesita sombra de dimensión. */
const isBuriedUnderStack = computed(() => {
  if (cascadeVisualFreeze.value) return cascadeVisualFreeze.value.buried
  return effectiveStackable.value && props.show && !isTopmost.value
})

/**
 * Sombra en pila vía data-attr + CSS !important.
 * Durante cascada se congela (incluye buried) hasta el final para no snappear
 * mientras el panel de arriba aún hace slide-out.
 * Sin pila: undefined → sombra por defecto del sheet (nunca box-shadow:none).
 */
const edgeShadowKind = computed(() => {
  if (cascadeVisualFreeze.value) return cascadeVisualFreeze.value.edge
  if (props.companion) return 'top'
  if (!effectiveStackable.value || !props.show) return undefined
  if (isBuriedUnderStack.value) return 'buried'
  if (isStackedOverlay.value) return 'top'
  return undefined
})

watch(slideOverCascadeActive, (active) => {
  if (active && props.show && !cascadeVisualFreeze.value) {
    cascadeVisualFreeze.value = {
      widthDepth: widthStackDepth.value,
      buried: effectiveStackable.value && !isTopmost.value,
      edge: edgeShadowKind.value,
    }
  }
  if (!active) {
    cascadeVisualFreeze.value = null
  }
})

const usesStandardWidth = computed(() => !props.maxWidth)

const panelWidthStyle = computed(() => {
  if (!usesStandardWidth.value) return undefined
  const pct = resolveSlideOverWidthPercent(widthStackDepth.value)
  return {
    '--slide-over-computed-width': `${pct}%`,
  }
})

const resolvedZIndex = computed(() => {
  if (leaveZIndexFreeze.value != null && (!props.show || leaveAsNonModal.value)) {
    return leaveZIndexFreeze.value
  }
  if (props.companion && (props.zIndex == null || props.zIndex === '')) {
    return resolveSlideOverZIndex(SLIDE_OVER_Z_INDEX_BASE + 20, 0)
  }
  return resolveSlideOverZIndex(props.zIndex, stackDepth.value)
})

function captureCurrentZIndex() {
  if (props.companion && (props.zIndex == null || props.zIndex === '')) {
    return resolveSlideOverZIndex(SLIDE_OVER_Z_INDEX_BASE + 20, 0)
  }
  return resolveSlideOverZIndex(props.zIndex, stackDepth.value)
}

const backdropZIndex = computed(() => resolvedZIndex.value + 1)

/** Una capa por debajo del Sheet (DialogContent) para no tapar el panel ni el chrome flotante (zIndex+1). */
const manualBackdropZIndex = computed(() => {
  const z = resolvedZIndex.value
  return z > 1 ? z - 1 : 99998
})

/**
 * Solo el panel raíz (depth 0) aporta velo/blur.
 * Con lightBackdrop se mantiene montado aunque haya sub-sliders encima,
 * para no montar/desmontar ni reanimar el velo al cerrar la cascada.
 */
const showManualBackdrop = computed(() => {
  if (!props.show) return false
  if (props.lightBackdrop && !props.companion && effectiveStackDepth.value === 0) {
    return true
  }
  if (isStackedOverlay.value) return false
  if (!effectiveModal.value && effectiveLightBackdrop.value) return true
  return false
})

/**
 * Panel deslizante: ancho % unificado (o maxWidth QuickQuote), sin padding del sheet base.
 * Ocultamos el DialogClose de SheetContent (lo reemplazamos por botón propio arriba).
 */
const sheetContentClass = computed(() => {
  const widthClass = resolveSlideOverWidthClass(props.size, props.maxWidth)
  const base = [
    widthClass,
    '[&>button:last-of-type]:hidden',
    'slide-over-panel-content flex h-full max-h-[100dvh] flex-col gap-0 overflow-visible p-0',
    'bg-white text-neutral-900 antialiased',
    // Sombra: data-slide-edge-shadow + CSS !important (no clase Tailwind)
    props.companion && props.side === 'left' ? 'border-r border-neutral-200/90' : null,
    props.companion && props.side === 'right' ? 'border-l border-neutral-200/90' : null,
  ].filter(Boolean)
  // Contrato: móvil siempre full-bleed salvo override explícito false (raro)
  if (props.mobileFullBleed) {
    base.push('max-md:!w-full max-md:!max-w-full max-md:!rounded-none')
  }
  return base
})

/** Barra superior: fila flex (contenido + X al final) */
const headerBarClass = computed(() => {
  const headerBorderClass = props.tabsHeader ? '' : 'border-b border-neutral-200/80'

  if (props.closeOnlyHeader) {
    return [
      'relative z-[60]',
      'flex shrink-0 items-start bg-[#fafcfb]',
      headerBorderClass,
      hasHeaderSlot.value ? 'justify-between gap-1.5 px-2 py-1.5 sm:gap-2 sm:px-3' : 'justify-end px-1.5 py-1',
    ]
  }
  const pad = props.compactHeader
    ? 'gap-2 px-2 py-2.5 sm:gap-3 sm:px-3 sm:py-3'
    : 'gap-3 px-4 py-3.5 sm:gap-4 sm:px-5'
  const standardHeaderBorder = props.tabsHeader ? '' : 'border-b border-neutral-200/90'
  return [
    'relative z-[60]',
    'flex shrink-0 justify-between bg-white/95 backdrop-blur-md',
    standardHeaderBorder,
    props.compactHeader ? 'items-center' : 'items-start sm:items-center',
    pad,
  ]
})

const closeButtonClass = computed(() => {
  const base =
    'inline-flex shrink-0 items-center justify-center rounded-full text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/25 focus-visible:ring-offset-2'
  if (props.closeOnlyHeader) {
    return [base, 'h-8 w-8']
  }
  return [
    base,
    'h-9 w-9 opacity-90 ring-offset-background hover:opacity-100',
    props.compactHeader ? 'self-center' : 'self-start sm:self-center',
  ]
})

const showCloseConfirm = ref(false)

function emitClose() {
  // Capturar antes de desregistrar de la pila: si era non-modal, mantenerlo en leave.
  leaveAsNonModal.value = !effectiveModal.value
  // Congelar z-index y ancho ANTES de emit('close') → unregister (depth cae a 0).
  leaveZIndexFreeze.value = captureCurrentZIndex()
  if (!props.maxWidth && !props.companion && effectiveStackable.value) {
    leaveWidthDepthFreeze.value = Math.min(stackDepth.value, STACK_MAX_DEPTH)
  }
  if (effectiveStackable.value && (stackDepth.value > 0 || getSlideOverStackSize() > 1)) {
    suppressSlideOverOutsideClose()
  }
  emit('close')
}

function requestClose() {
  if (props.unsavedChanges) {
    showCloseConfirm.value = true
    return
  }
  emitClose()
}

const unregisterCloseHandler = registerSlideOverCloseHandler(panelId, {
  close: () => {
    showCloseConfirm.value = false
    emitClose()
  },
})

onBeforeUnmount(() => {
  unregisterCloseHandler()
})

function onManualBackdropPointerDown(event) {
  event.preventDefault()
  // Evita que el gesto que abrió el panel (p. ej. DropdownMenu) cierre el velo recién montado.
  if (isSlideOverOutsideCloseSuppressed()) return
  if (getSlideOverStackSize() > 1) {
    closeAllSlideOversCascaded()
    return
  }
  suppressSlideOverOutsideClose()
  requestClose()
}

function onOpenChange(open) {
  if (open || props.companion || !props.show) return
  if (isSlideOverOutsideCloseSuppressed()) return
  if (effectiveStackable.value && stackSize.value > 1) return
  if (effectiveStackable.value && !isTopmost.value) return
  requestClose()
}

function confirmCloseAnyway() {
  showCloseConfirm.value = false
  emitClose()
}

watch(
  () => props.show,
  (open) => {
    if (!open) showCloseConfirm.value = false
    if (open) {
      leaveAsNonModal.value = false
      leaveZIndexFreeze.value = null
      leaveWidthDepthFreeze.value = null
      // Non-modal + lightBackdrop: el dismiss del menú/dropdown no debe cerrar al abrir.
      suppressSlideOverOutsideClose(500)
    }
  }
)

defineExpose({ requestClose })
</script>

<style scoped>
/* Ancho unificado desktop: % desde CSS vars / --slide-over-computed-width
   :global porque la clase vive en el root de SheetContent (hijo). */
:global(.slide-over-panel-content.slide-over-width-standard) {
  width: var(--slide-over-computed-width, var(--slide-over-width, 45%)) !important;
  max-width: var(--slide-over-computed-width, var(--slide-over-width, 45%)) !important;
}

@media (max-width: 767px) {
  :global(.slide-over-panel-content.slide-over-width-standard) {
    width: 100% !important;
    max-width: 100% !important;
  }
}

/* Sombra de dimensión en pila: gana a sheetVariants (shadow Tailwind).
   No forzar box-shadow:none — al quedar solo el nivel 1 debe conservar
   la sombra por defecto del sheet (si no, hay un flash al salir de buried). */
:global(.slide-over-panel-content[data-slide-edge-shadow='buried']) {
  box-shadow: -6px 0 22px -4px rgba(15, 23, 42, 0.26) !important;
  transition: box-shadow 280ms ease-out;
}
:global(.slide-over-panel-content[data-slide-edge-shadow='top']) {
  box-shadow: -8px 0 28px -4px rgba(15, 23, 42, 0.28) !important;
  transition: box-shadow 280ms ease-out;
}

.slide-over-panel-content {
  touch-action: pan-y;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
}
.slide-over-content {
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}

/* Portal vacío (IndexCrud/Embedded antes de teletransportar ActionBar): sin chrome. */
:global(.slide-over-footer:has(.slide-over-footer-portal:empty)),
.slide-over-footer:empty {
  display: none;
}
</style>
