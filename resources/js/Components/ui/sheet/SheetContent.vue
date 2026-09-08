<script setup>
import { computed, useAttrs } from "vue";
import { reactiveOmit } from "@vueuse/core";
import { X } from "lucide-vue-next";
import {
  DialogClose,
  DialogContent,
  DialogOverlay,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui";
import { cn } from "@/lib/utils";
import { sheetVariants, SheetTitle, SheetDescription } from ".";
import { isSlideOverOutsideCloseSuppressed } from "@/composables/useSlideOverStack";

defineOptions({
  inheritAttrs: false,
});

const props = defineProps({
  class: { type: null, required: false },
  side: { type: null, required: false },
  zIndex: { type: [Number, String], required: false },
  /** Velo claro + blur (misma base que modales en Step2: bg-black/20 + backdrop-blur-sm) */
  lightBackdrop: { type: Boolean, required: false },
  /** false: ignora pointer/focus/interact outside y Escape (panel inferior en stack). */
  allowOutsideClose: { type: Boolean, default: true },
  forceMount: { type: Boolean, required: false },
  disableOutsidePointerEvents: { type: Boolean, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  /** Título para lectores de pantalla (sr-only si no hay header visible). */
  accessibilityTitle: { type: String, default: "Panel lateral" },
  accessibilityDescription: {
    type: String,
    default: "Panel lateral con opciones y controles.",
  },
});

const emits = defineEmits([
  "escapeKeyDown",
  "pointerDownOutside",
  "focusOutside",
  "interactOutside",
  "openAutoFocus",
  "closeAutoFocus",
]);

const delegatedProps = reactiveOmit(
  props,
  "class",
  "side",
  "zIndex",
  "lightBackdrop",
  "allowOutsideClose",
  "accessibilityTitle",
  "accessibilityDescription",
);
/** z-index solo por estilo inline: evita que la clase z-50 del Sheet compita con valores altos (p. ej. cotizador). */
const zStyle = computed(() => {
  if (props.zIndex == null || props.zIndex === "") return undefined;
  const n = Number(props.zIndex);
  return { zIndex: Number.isFinite(n) ? n : 99999 };
});

const overlayBackdropTone = computed(() =>
  props.lightBackdrop ? "bg-black/20 backdrop-blur-sm" : "bg-black/65 backdrop-blur-sm"
);
const overlayBaseClass = computed(
  () =>
    `fixed inset-0 ${overlayBackdropTone.value} data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0`
);
const overlayClass = computed(() =>
  zStyle.value ? overlayBaseClass.value : `${overlayBaseClass.value} z-50`
);

/** El panel también traía z-50 en sheetVariants; con zIndex alto solo debe aplicar el inline. */
const sheetPanelClass = computed(() => {
  const v = sheetVariants({ side: props.side });
  return zStyle.value ? v.replace(/\bz-50\b/g, "").replace(/\s+/g, " ").trim() : v;
});

const attrs = useAttrs();

const forwarded = useForwardPropsEmits(delegatedProps, emits);

const passthroughAttrs = computed(() => {
  const result = {};
  for (const [key, value] of Object.entries(attrs)) {
    if (key.startsWith("data-") || key.startsWith("aria-")) {
      result[key] = value;
    }
  }
  return result;
});

/** Fusiona z-index con style del padre (p. ej. --slide-over-computed-width). inheritAttrs:false lo descartaba. */
const contentStyle = computed(() => {
  const merged = zStyle.value ? { ...zStyle.value } : {};
  const attrStyle = attrs.style;
  if (attrStyle && typeof attrStyle === "object" && !Array.isArray(attrStyle)) {
    Object.assign(merged, attrStyle);
  }
  return Object.keys(merged).length ? merged : undefined;
});

function isInteractionOnNestedOverlay(event) {
  const target = event.detail?.originalEvent?.target;
  return target instanceof Element && (
    target.closest("[data-sheet-backdrop-chrome]") ||
    target.closest("[data-base-modal-portal]") ||
    target.closest("[data-slide-over-nested-layer]") ||
    target.closest("[data-searchable-select-dropdown]") ||
    target.closest("[data-slide-over-floating-portal]") ||
    target.closest(".shipment-filter-datepicker-panel")
  );
}

function shouldBlockOutsideInteraction(event) {
  if (!props.allowOutsideClose) return true;
  // Tras abrir un slide non-modal (p. ej. desde DropdownMenu), ignorar el dismiss fantasma.
  if (isSlideOverOutsideCloseSuppressed()) return true;
  if (isInteractionOnNestedOverlay(event)) return true;
  const target = event.detail?.originalEvent?.target;
  if (target instanceof Element && target.closest("[data-slide-over-backdrop]")) {
    return true;
  }
  return false;
}

/** No cerrar el Sheet por interacciones en chrome flotante ni en BaseModal apilado (p. ej. compartir WhatsApp). */
function handlePointerDownOutside(event) {
  if (shouldBlockOutsideInteraction(event)) event.preventDefault();
}

function handleFocusOutside(event) {
  if (shouldBlockOutsideInteraction(event)) event.preventDefault();
}

function handleInteractOutside(event) {
  if (shouldBlockOutsideInteraction(event)) event.preventDefault();
}

function handleEscapeKeyDown(event) {
  if (!props.allowOutsideClose) event.preventDefault();
}
</script>

<template>
  <DialogPortal>
    <DialogOverlay
      :style="zStyle"
      :class="overlayClass"
    />
    <DialogContent
      :style="contentStyle"
      :class="cn(sheetPanelClass, props.class)"
      v-bind="{ ...forwarded, ...passthroughAttrs }"
      @pointer-down-outside="handlePointerDownOutside"
      @focus-outside="handleFocusOutside"
      @interact-outside="handleInteractOutside"
      @escape-key-down="handleEscapeKeyDown"
    >
      <SheetTitle class="sr-only">{{ accessibilityTitle }}</SheetTitle>
      <SheetDescription class="sr-only">{{ accessibilityDescription }}</SheetDescription>
      <slot />

      <DialogClose
        class="absolute right-3 top-3 z-10 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-neutral-500 opacity-90 ring-offset-background transition-colors hover:bg-neutral-100 hover:text-neutral-900 hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/25 focus-visible:ring-offset-2 disabled:pointer-events-none"
        aria-label="Cerrar panel"
      >
        <X class="h-4 w-4" stroke-width="2" />
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
