<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { Copy, Download, RefreshCw } from 'lucide-vue-next'

const props = defineProps({
  orderId: { type: [Number, String], required: true },
  active: { type: Boolean, default: false },
  currency: { type: String, default: 'MXN' },
})

const loading = ref(false)
const error = ref(null)
const payload = ref(null)
const copied = ref(false)
const pdfObjectUrl = ref(null)
let copyTimer = null

const status = computed(() => payload.value?.status ?? null)
const statusError = computed(() => {
  if (status.value === 'error') {
    return payload.value?.error || error.value || 'No se pudo consultar la factura SAT.'
  }
  if (error.value && status.value !== 'forbidden' && status.value !== 'not_found' && status.value !== 'found') {
    return error.value
  }
  return null
})
const sourceLabel = computed(() => {
  const source = payload.value?.source
  if (source === 'facturador') return 'Facturador SAT (ML)'
  if (source === 'fiscal_documents') return 'Documentos fiscales del pack'
  return null
})

const xmlText = computed(() => {
  const encoded = payload.value?.xml_base64
  if (typeof encoded !== 'string' || encoded === '') return ''
  try {
    const binary = atob(encoded)
    const bytes = Uint8Array.from(binary, (ch) => ch.charCodeAt(0))
    return new TextDecoder('utf-8').decode(bytes)
  } catch {
    return ''
  }
})

const hasPdf = computed(() => typeof payload.value?.pdf_base64 === 'string' && payload.value.pdf_base64 !== '')
const hasXml = computed(() => typeof payload.value?.xml_base64 === 'string' && payload.value.xml_base64 !== '')

function invoiceUrl(name) {
  if (typeof window === 'undefined' || !window.route) {
    return `/orders/${props.orderId}/invoice${name === 'json' ? '' : `/${name}`}`
  }
  try {
    if (name === 'json') return window.route('orders.invoice', props.orderId)
    if (name === 'pdf') return window.route('orders.invoice.pdf', props.orderId)
    return window.route('orders.invoice.xml', props.orderId)
  } catch {
    return `/orders/${props.orderId}/invoice${name === 'json' ? '' : `/${name}`}`
  }
}

function revokePdfUrl() {
  if (pdfObjectUrl.value && typeof URL !== 'undefined') {
    URL.revokeObjectURL(pdfObjectUrl.value)
  }
  pdfObjectUrl.value = null
}

function refreshPdfPreview(base64) {
  revokePdfUrl()
  if (typeof base64 !== 'string' || base64 === '' || typeof URL === 'undefined') return
  try {
    const binary = atob(base64)
    const bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i += 1) {
      bytes[i] = binary.charCodeAt(i)
    }
    const blob = new Blob([bytes], { type: 'application/pdf' })
    pdfObjectUrl.value = URL.createObjectURL(blob)
  } catch {
    pdfObjectUrl.value = null
  }
}

async function load() {
  if (!props.orderId) return
  loading.value = true
  error.value = null
  try {
    const data = await fetchSlidePayload(invoiceUrl('json'), { cache: 'no-store' })
    payload.value = data
    refreshPdfPreview(data?.pdf_base64)
  } catch (e) {
    payload.value = e?.data && typeof e.data === 'object' ? e.data : null
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo consultar la factura SAT.'
    revokePdfUrl()
  } finally {
    loading.value = false
  }
}

async function copyXml() {
  if (!xmlText.value || typeof navigator === 'undefined' || !navigator.clipboard?.writeText) return
  try {
    await navigator.clipboard.writeText(xmlText.value)
    copied.value = true
    if (copyTimer) clearTimeout(copyTimer)
    copyTimer = setTimeout(() => {
      copied.value = false
    }, 1500)
  } catch {
    copied.value = false
  }
}

function downloadHref(kind) {
  return invoiceUrl(kind)
}

watch(
  () => [props.active, props.orderId],
  ([active, id], previous) => {
    if (!active || !id) return
    const prevActive = previous?.[0]
    const prevId = previous?.[1]
    if (active && (!payload.value || prevId !== id || !prevActive)) {
      load()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  revokePdfUrl()
  if (copyTimer) clearTimeout(copyTimer)
})
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div v-if="loading" class="flex flex-1 items-center justify-center py-10 text-xs text-neutral-500">
      Buscando factura SAT…
    </div>

    <div
      v-else-if="statusError"
      class="rounded-lg border border-amber-200 bg-amber-50/80 px-4 py-6 text-sm text-amber-950"
    >
      <p class="font-semibold">No se pudo consultar la factura</p>
      <p class="mt-1 text-xs leading-relaxed text-amber-900">{{ statusError }}</p>
      <Button type="button" size="sm" variant="outline" class="mt-3 h-7 px-2.5 text-[11px]" @click="load">
        <RefreshCw class="mr-1 size-3" />
        Reintentar
      </Button>
    </div>

    <div
      v-else-if="status === 'forbidden'"
      class="rounded-lg border border-amber-200 bg-amber-50/80 px-4 py-6 text-sm text-amber-950"
    >
      <p class="font-semibold">Sin permiso de Facturación</p>
      <p class="mt-1 text-xs leading-relaxed text-amber-900">
        {{ payload?.error || 'La app de Mercado Libre no tiene el permiso Facturación. Actívalo en DevCenter y vuelve a autorizar la cuenta.' }}
      </p>
      <Button type="button" size="sm" variant="outline" class="mt-3 h-7 px-2.5 text-[11px]" @click="load">
        <RefreshCw class="mr-1 size-3" />
        Reintentar
      </Button>
    </div>

    <div
      v-else-if="status === 'not_found'"
      class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500"
    >
      No hay factura SAT para esta orden.
    </div>

    <div v-else-if="status === 'found'" class="flex min-h-0 flex-1 flex-col gap-3">
      <div class="rounded-lg border border-slate-200 bg-white p-3">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Factura SAT</p>
            <p class="mt-0.5 font-mono text-xs text-slate-800">
              {{ payload?.uuid || 'Sin UUID' }}
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-1.5">
            <Badge v-if="sourceLabel" variant="secondary" class="text-[10px]">
              {{ sourceLabel }}
            </Badge>
            <a
              v-if="hasPdf"
              :href="downloadHref('pdf')"
              target="_blank"
              rel="noopener"
              class="inline-flex h-7 items-center rounded-md border border-input bg-white px-2.5 text-[11px] font-medium hover:bg-accent"
            >
              <Download class="mr-1 size-3" />
              PDF
            </a>
            <a
              v-if="hasXml"
              :href="downloadHref('xml')"
              class="inline-flex h-7 items-center rounded-md border border-input bg-white px-2.5 text-[11px] font-medium hover:bg-accent"
            >
              <Download class="mr-1 size-3" />
              XML
            </a>
            <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="load">
              <RefreshCw class="size-3" />
            </Button>
          </div>
        </div>

        <dl class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-[10px] uppercase tracking-wide text-slate-500">Folio</dt>
            <dd class="mt-0.5 text-xs text-slate-800">
              {{ [payload?.serie, payload?.folio].filter(Boolean).join('-') || '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-[10px] uppercase tracking-wide text-slate-500">RFC emisor</dt>
            <dd class="mt-0.5 font-mono text-xs text-slate-800">{{ payload?.issuer_rfc || '—' }}</dd>
          </div>
          <div>
            <dt class="text-[10px] uppercase tracking-wide text-slate-500">RFC receptor</dt>
            <dd class="mt-0.5 font-mono text-xs text-slate-800">{{ payload?.receiver_rfc || '—' }}</dd>
          </div>
          <div>
            <dt class="text-[10px] uppercase tracking-wide text-slate-500">Total</dt>
            <dd class="mt-0.5 text-xs font-semibold tabular-nums text-slate-800">
              <MoneyText :amount="payload?.total" :currency="currency" />
            </dd>
          </div>
        </dl>
      </div>

      <div v-if="hasPdf" class="min-h-[18rem] flex-1 overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
        <iframe
          v-if="pdfObjectUrl"
          :src="pdfObjectUrl"
          title="Factura SAT PDF"
          class="h-full min-h-[18rem] w-full border-0"
        />
        <p v-else class="px-3 py-6 text-center text-xs text-slate-500">
          No se pudo previsualizar el PDF.
          <a :href="downloadHref('pdf')" target="_blank" rel="noopener" class="font-medium text-brand hover:underline">
            Abrir archivo
          </a>
        </p>
      </div>

      <div v-if="hasXml" class="rounded-lg border border-slate-200">
        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-3 py-2">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">XML CFDI</p>
          <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="copyXml">
            <Copy class="mr-1 size-3" />
            {{ copied ? 'Copiado' : 'Copiar' }}
          </Button>
        </div>
        <pre class="max-h-64 overflow-auto px-3 py-2 text-[11px] leading-relaxed text-slate-700 whitespace-pre-wrap break-all">{{ xmlText || '—' }}</pre>
      </div>
    </div>

    <div v-else class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
      <p>No se pudo determinar si hay factura SAT.</p>
      <button type="button" class="mt-2 text-xs font-medium text-brand hover:text-brand-hover" @click="load">
        Reintentar
      </button>
    </div>
  </div>
</template>
