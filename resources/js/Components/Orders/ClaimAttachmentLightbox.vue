<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import Button from '@/Components/ui/Button.vue'
import { ChevronLeft, ChevronRight, Share2, X } from 'lucide-vue-next'

const props = defineProps({
  open: { type: Boolean, default: false },
  images: { type: Array, default: () => [] },
  initialIndex: { type: Number, default: 0 },
})

const emit = defineEmits(['close', 'update:index'])

const index = ref(0)
const sharing = ref(false)
const shareError = ref(null)

const current = computed(() => props.images[index.value] ?? null)
const hasMultiple = computed(() => props.images.length > 1)

watch(
  () => [props.open, props.initialIndex, props.images.length],
  ([isOpen]) => {
    if (!isOpen) {
      shareError.value = null
      sharing.value = false
      return
    }
    const max = Math.max(0, props.images.length - 1)
    index.value = Math.min(Math.max(0, props.initialIndex), max)
    shareError.value = null
  },
  { immediate: true },
)

watch(index, (value) => {
  emit('update:index', value)
})

function close() {
  emit('close')
}

function prev() {
  if (!hasMultiple.value) return
  index.value = (index.value - 1 + props.images.length) % props.images.length
}

function next() {
  if (!hasMultiple.value) return
  index.value = (index.value + 1) % props.images.length
}

function onKeydown(e) {
  if (!props.open) return
  if (e.key === 'Escape') {
    e.preventDefault()
    close()
  } else if (e.key === 'ArrowLeft') {
    prev()
  } else if (e.key === 'ArrowRight') {
    next()
  }
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

async function shareCurrent() {
  if (!current.value || sharing.value) return
  sharing.value = true
  shareError.value = null
  try {
    const file = await fetchImageAsFile(current.value)
    if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
      const payload = {
        title: current.value.name || 'Adjunto',
        text: current.value.name || 'Imagen del reclamo',
      }
      if (file && navigator.canShare?.({ files: [file] })) {
        payload.files = [file]
      } else if (current.value.url) {
        payload.url = absoluteUrl(current.value.url)
      }
      await navigator.share(payload)
      return
    }

    if (file) {
      downloadFile(file)
      return
    }

    shareError.value = 'Este dispositivo no soporta compartir de forma nativa.'
  } catch (e) {
    if (e?.name !== 'AbortError') {
      shareError.value =
        typeof e?.message === 'string' ? e.message : 'No se pudo compartir la imagen.'
    }
  } finally {
    sharing.value = false
  }
}

function absoluteUrl(url) {
  if (!url) return url
  try {
    return new URL(url, window.location.origin).href
  } catch {
    return url
  }
}

async function fetchImageAsFile(image) {
  try {
    const response = await fetch(image.url, {
      credentials: 'same-origin',
      headers: { Accept: '*/*' },
    })
    if (!response.ok) return null
    const blob = await response.blob()
    const type = blob.type || 'image/jpeg'
    const name = sanitizeFilename(image.name || image.filename || 'adjunto.jpg', type)
    return new File([blob], name, { type })
  } catch {
    return null
  }
}

function sanitizeFilename(name, mime) {
  const cleaned = String(name).replace(/[^\w.\-() ]+/g, '_').trim() || 'adjunto'
  if (/\.[a-z0-9]+$/i.test(cleaned)) return cleaned
  const ext =
    mime === 'image/png'
      ? 'png'
      : mime === 'image/webp'
        ? 'webp'
        : mime === 'image/gif'
          ? 'gif'
          : 'jpg'
  return `${cleaned}.${ext}`
}

function downloadFile(file) {
  const objectUrl = URL.createObjectURL(file)
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = file.name || 'adjunto.jpg'
  anchor.rel = 'noopener'
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(objectUrl)
}
</script>

<template>
  <div
    v-if="open"
    class="absolute inset-0 z-30 flex flex-col bg-slate-950"
    role="dialog"
    aria-modal="true"
    aria-label="Vista de adjunto"
  >
    <div class="flex shrink-0 items-start justify-between gap-2 border-b border-white/10 px-3 py-2.5">
      <div class="min-w-0">
        <p class="text-[10px] font-medium uppercase tracking-wide text-white/55">Adjunto</p>
        <h2 class="truncate text-sm font-semibold text-white">
          {{ current?.name || 'Imagen' }}
        </h2>
      </div>
      <div class="flex shrink-0 items-center gap-1">
        <Button
          type="button"
          size="icon"
          variant="ghost"
          class="size-8 rounded-full text-white hover:bg-white/10 hover:text-white"
          :disabled="sharing || !current"
          title="Compartir"
          aria-label="Compartir"
          @click="shareCurrent"
        >
          <Share2 class="size-4" />
        </Button>
        <Button
          type="button"
          size="icon"
          variant="ghost"
          class="size-8 rounded-full text-white hover:bg-white/10 hover:text-white"
          title="Cerrar"
          aria-label="Cerrar"
          @click="close"
        >
          <X class="size-4" />
        </Button>
      </div>
    </div>

    <div class="relative flex min-h-0 flex-1 items-center justify-center px-2 py-3">
      <button
        v-if="hasMultiple"
        type="button"
        class="absolute left-2 z-10 rounded-full bg-white/90 p-2 text-slate-800 shadow hover:bg-white"
        aria-label="Anterior"
        @click="prev"
      >
        <ChevronLeft class="size-5" />
      </button>

      <img
        v-if="current"
        :src="current.url"
        :alt="current.name || 'Adjunto'"
        class="max-h-full max-w-full object-contain"
      />

      <button
        v-if="hasMultiple"
        type="button"
        class="absolute right-2 z-10 rounded-full bg-white/90 p-2 text-slate-800 shadow hover:bg-white"
        aria-label="Siguiente"
        @click="next"
      >
        <ChevronRight class="size-5" />
      </button>
    </div>

    <div class="shrink-0 space-y-1 px-3 pb-3 pt-1">
      <p
        v-if="hasMultiple"
        class="text-center text-xs text-white/70"
      >
        {{ index + 1 }} / {{ images.length }}
      </p>
      <p
        v-if="shareError"
        class="text-center text-xs text-red-300"
      >
        {{ shareError }}
      </p>
    </div>
  </div>
</template>
