<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import ClaimAttachmentLightbox from '@/Components/Orders/ClaimAttachmentLightbox.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime, formatRelativeShort } from '@/lib/utils'
import {
  claimStageLabel,
  claimStatusLabel,
  formatOrderMessageHtml,
} from '@/lib/formatOrderMessage'
import {
  Handshake,
  Paperclip,
  RefreshCw,
  SendHorizontal,
} from 'lucide-vue-next'

const props = defineProps({
  orderId: { type: [Number, String], required: true },
  claims: { type: Array, default: () => [] },
  active: { type: Boolean, default: false },
  loadingClaims: { type: Boolean, default: false },
})

const sortedClaims = computed(() => {
  const rows = Array.isArray(props.claims) ? [...props.claims] : []
  return rows.sort((a, b) => {
    const aOpen = a?.status === 'opened' ? 1 : 0
    const bOpen = b?.status === 'opened' ? 1 : 0
    if (aOpen !== bOpen) return bOpen - aOpen
    const aAt = a?.opened_at ? new Date(a.opened_at).getTime() : 0
    const bAt = b?.opened_at ? new Date(b.opened_at).getTime() : 0
    return bAt - aAt
  })
})

const selectedClaimId = ref(null)
const loading = ref(false)
const sending = ref(false)
const error = ref(null)
const warning = ref(null)
const sendError = ref(null)
const payload = ref(null)
const draft = ref('')
const listEl = ref(null)
const failedAttachmentKeys = ref(new Set())
const lightboxOpen = ref(false)
const lightboxImages = ref([])
const lightboxIndex = ref(0)

watch(
  sortedClaims,
  (rows) => {
    if (!rows.length) {
      selectedClaimId.value = null
      return
    }
    const stillThere = rows.some((c) => c.id === selectedClaimId.value)
    if (!stillThere) {
      selectedClaimId.value = rows[0].id
    }
  },
  { immediate: true },
)

const selectedClaim = computed(() => {
  const fromPayload = payload.value?.claim
  if (fromPayload && fromPayload.id === selectedClaimId.value) {
    return fromPayload
  }
  return sortedClaims.value.find((c) => c.id === selectedClaimId.value) ?? null
})

const messages = computed(() => payload.value?.messages ?? [])
const canMessageMediator = computed(
  () => Boolean(selectedClaim.value?.can_message_mediator),
)

const reputationLabel = computed(() => {
  const value = selectedClaim.value?.affects_reputation
  if (value === 'not_affected') return 'No afectó tu reputación'
  if (value === 'affected') return 'Afectó tu reputación'
  if (value === 'not_applies') return 'No aplica a reputación'
  return null
})

const incentiveLabel = computed(() => {
  const claim = selectedClaim.value
  if (!claim || claim.has_incentive !== true) return null
  return 'Puedes resolverlo a tiempo'
})

const bannerTitle = computed(() => {
  const claim = selectedClaim.value
  if (!claim) return ''
  const id = claim.external_claim_id ?? claim.id
  const stage = claim.stage
  const prefix =
    stage === 'dispute' || stage === 'recontact'
      ? 'Mediación iniciada'
      : claim.status === 'opened'
        ? 'Reclamo abierto'
        : 'Reclamo'
  const parts = [`${prefix} n.º ${id}`]
  if (reputationLabel.value) parts.push(reputationLabel.value)
  if (incentiveLabel.value) parts.push(incentiveLabel.value)
  return parts.join(' | ')
})

const reasonText = computed(() => {
  const claim = selectedClaim.value
  if (!claim) return null
  return claim.problem || claim.reason_detail || claim.reason || claim.reason_id || null
})

const statusTitle = computed(() => selectedClaim.value?.status_title || null)
const statusDescription = computed(() => selectedClaim.value?.status_description || null)

const timelineItems = computed(() => {
  const items = []
  let lastDateKey = null
  for (const msg of messages.value) {
    const dateKey = msg.sent_at ? String(msg.sent_at).slice(0, 10) : null
    if (dateKey && dateKey !== lastDateKey) {
      items.push({ type: 'date', key: `d-${dateKey}`, label: formatDayLabel(msg.sent_at) })
      lastDateKey = dateKey
    }
    items.push({ type: 'message', key: `m-${msg.id}`, msg })
  }
  return items
})

function formatDayLabel(value) {
  try {
    return new Intl.DateTimeFormat('es-MX', {
      day: 'numeric',
      month: 'long',
    }).format(new Date(value))
  } catch {
    return formatDateTime(value)
  }
}

function roleLabel(role) {
  if (role === 'mediator') return 'Mercado Libre'
  if (role === 'complainant') return 'Comprador'
  if (role === 'respondent') return 'Tú'
  return role || '—'
}

function isOutbound(role) {
  return role === 'respondent'
}

function isMediator(role) {
  return role === 'mediator'
}

function attachmentList(msg) {
  const rows = msg?.meta?.attachments
  return Array.isArray(rows) ? rows.filter((f) => f && f.filename) : []
}

function isImageAttachment(file) {
  const type = typeof file?.type === 'string' ? file.type : ''
  if (type.startsWith('image/')) return true
  const name = String(file?.original_filename || file?.filename || '').toLowerCase()
  return /\.(jpe?g|png|gif|webp|bmp)$/.test(name)
}

function imageAttachments(msg) {
  return attachmentList(msg).filter(isImageAttachment)
}

function fileAttachments(msg) {
  return attachmentList(msg).filter((f) => !isImageAttachment(f))
}

function attachmentUrl(filename) {
  if (!props.orderId || !selectedClaimId.value || !filename) return '#'
  if (typeof window !== 'undefined' && window.route) {
    return window.route('orders.claims.attachments', {
      order: props.orderId,
      claim: selectedClaimId.value,
      filename,
    })
  }
  return `/orders/${props.orderId}/claims/${selectedClaimId.value}/attachments/${encodeURIComponent(filename)}`
}

function attachmentKey(msgId, filename) {
  return `${msgId}:${filename}`
}

function markAttachmentFailed(msgId, filename) {
  const next = new Set(failedAttachmentKeys.value)
  next.add(attachmentKey(msgId, filename))
  failedAttachmentKeys.value = next
}

function attachmentFailed(msgId, filename) {
  return failedAttachmentKeys.value.has(attachmentKey(msgId, filename))
}

function imageGridClass(count) {
  if (count <= 1) return 'grid-cols-1'
  if (count === 2) return 'grid-cols-2'
  return 'grid-cols-2'
}

function openLightbox(msg, index) {
  const images = imageAttachments(msg).map((file) => ({
    url: attachmentUrl(file.filename),
    name: file.original_filename || file.filename || 'Adjunto',
    filename: file.filename,
  }))
  if (!images.length) return
  lightboxImages.value = images
  lightboxIndex.value = Math.min(Math.max(0, index), images.length - 1)
  lightboxOpen.value = true
}

function closeLightbox() {
  lightboxOpen.value = false
}

function getXsrfToken() {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

async function scrollToBottom() {
  await nextTick()
  if (listEl.value) {
    listEl.value.scrollTop = listEl.value.scrollHeight
  }
}

async function load({ refresh = true } = {}) {
  if (!props.orderId || !selectedClaimId.value) return
  loading.value = true
  error.value = null
  warning.value = null
  try {
    const base =
      typeof window !== 'undefined' && window.route
        ? window.route('orders.claims.messages', {
            order: props.orderId,
            claim: selectedClaimId.value,
          })
        : `/orders/${props.orderId}/claims/${selectedClaimId.value}/messages`
    const url = refresh ? base : `${base}?cached_only=1`
    payload.value = await fetchSlidePayload(url, { cache: 'no-store' })
    warning.value = payload.value?.warning ?? null
    failedAttachmentKeys.value = new Set()
    await scrollToBottom()
  } catch (e) {
    error.value =
      typeof e?.message === 'string'
        ? e.message
        : 'No se pudieron cargar los mensajes del reclamo.'
  } finally {
    loading.value = false
  }
}

async function send() {
  if (!props.orderId || !selectedClaimId.value || sending.value || !canMessageMediator.value) {
    return
  }
  const text = draft.value.trim()
  if (!text) {
    sendError.value = 'Escribe un mensaje.'
    return
  }
  sending.value = true
  sendError.value = null
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('orders.claims.messages.send', {
            order: props.orderId,
            claim: selectedClaimId.value,
          })
        : `/orders/${props.orderId}/claims/${selectedClaimId.value}/messages`
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({ text }),
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new Error(data.message || `Error ${response.status}`)
    }
    payload.value = data
    draft.value = ''
    await scrollToBottom()
  } catch (e) {
    sendError.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo enviar el mensaje.'
  } finally {
    sending.value = false
  }
}

watch(
  () => [props.active, props.orderId, selectedClaimId.value],
  ([active, orderId, claimId]) => {
    if (active && orderId && claimId) {
      load({ refresh: true })
    }
    if (!active) {
      sendError.value = null
      draft.value = ''
    }
  },
  { immediate: true },
)
</script>

<template>
  <div class="relative flex min-h-[28rem] flex-1 flex-col overflow-hidden">
    <div
      v-if="loadingClaims"
      class="flex flex-1 items-center justify-center py-12 text-xs text-neutral-500"
    >
      Cargando reclamos…
    </div>
    <div
      v-else-if="sortedClaims.length === 0"
      class="flex flex-1 flex-col items-center justify-center gap-1.5 py-12 text-center"
    >
      <div
        class="mb-0.5 flex size-8 items-center justify-center rounded-full bg-amber-100 text-amber-700"
      >
        <Handshake class="size-4" />
      </div>
      <p class="text-[13px] font-semibold tracking-tight text-slate-900">Sin reclamos</p>
      <p class="max-w-xs text-[11px] text-muted-foreground">
        Los reclamos de esta orden aparecerán aquí cuando se sincronicen desde Mercado Libre.
      </p>
    </div>
    <template v-else>
      <div class="flex items-center justify-between gap-2 pb-2">
        <div class="min-w-0 flex-1">
          <p class="text-[13px] font-semibold tracking-tight text-slate-900">
            Mensajes con Mercado Libre
          </p>
          <select
            v-if="sortedClaims.length > 1"
            v-model="selectedClaimId"
            class="mt-1.5 h-8 w-full max-w-sm rounded-lg border border-slate-200/80 bg-white px-2.5 text-xs"
          >
            <option v-for="claim in sortedClaims" :key="claim.id" :value="claim.id">
              #{{ claim.external_claim_id ?? claim.id }}
              · {{ claimStatusLabel(claim.status) }}
              · {{ claimStageLabel(claim.stage) }}
            </option>
          </select>
        </div>
        <Button
          type="button"
          size="icon"
          variant="outline"
          class="size-7 shrink-0 rounded-full"
          :disabled="loading"
          title="Actualizar"
          @click="load({ refresh: true })"
        >
          <RefreshCw class="size-3.5" :class="{ 'animate-spin': loading }" />
        </Button>
      </div>

      <div
        v-if="selectedClaim"
        class="rounded-xl border border-slate-200/70 bg-slate-50/80 px-3 py-2 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
      >
        <div class="flex flex-wrap items-start justify-between gap-1.5">
          <p class="text-[12px] font-semibold leading-snug tracking-tight text-slate-900">
            {{ bannerTitle }}
          </p>
          <div class="flex flex-wrap gap-1">
            <Badge
              :variant="selectedClaim.status === 'opened' ? 'danger' : 'secondary'"
              class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
            >
              {{ claimStatusLabel(selectedClaim.status) }}
            </Badge>
            <Badge
              v-if="selectedClaim.stage"
              variant="outline"
              class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
            >
              {{ claimStageLabel(selectedClaim.stage) }}
            </Badge>
          </div>
        </div>
        <p v-if="reasonText" class="mt-1 text-[11px] leading-snug text-slate-600">
          {{ reasonText }}
        </p>
        <div
          v-if="statusTitle || statusDescription"
          class="mt-1.5 rounded-lg bg-amber-50/90 px-2.5 py-1.5"
        >
          <p v-if="statusTitle" class="text-[11px] font-medium text-amber-950">
            {{ statusTitle }}
          </p>
          <p v-if="statusDescription" class="mt-0.5 text-[11px] text-amber-900/80">
            {{ statusDescription }}
          </p>
        </div>
      </div>

      <div ref="listEl" class="mt-2 min-h-0 flex-1 overflow-auto py-0.5">
        <div
          v-if="loading && !messages.length"
          class="flex justify-center py-12 text-xs text-neutral-500"
        >
          Cargando mensajes…
        </div>
        <div
          v-else-if="error"
          class="flex flex-col items-center gap-2 py-12 text-center"
        >
          <p class="text-xs text-neutral-700">{{ error }}</p>
          <button
            type="button"
            class="text-xs font-medium text-brand hover:text-brand-hover"
            @click="load({ refresh: true })"
          >
            Reintentar
          </button>
        </div>
        <template v-else>
          <p
            v-if="warning"
            class="mb-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-800"
          >
            {{ warning }}
          </p>
          <p
            v-if="messages.length === 0"
            class="py-8 text-center text-xs text-muted-foreground"
          >
            Aún no hay mensajes en esta mediación.
          </p>
          <ul v-else class="space-y-2 px-0.5">
            <li v-for="item in timelineItems" :key="item.key">
              <div
                v-if="item.type === 'date'"
                class="flex items-center justify-center py-0.5"
              >
                <span
                  class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium capitalize text-slate-600"
                >
                  {{ item.label }}
                </span>
              </div>
              <div
                v-else
                class="flex gap-1.5"
                :class="isOutbound(item.msg.sender_role) ? 'flex-row-reverse' : 'flex-row'"
              >
                <div
                  v-if="!isOutbound(item.msg.sender_role)"
                  class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full"
                  :class="
                    isMediator(item.msg.sender_role)
                      ? 'bg-[#ffe600] text-slate-900'
                      : 'bg-slate-200 text-slate-700'
                  "
                >
                  <Handshake v-if="isMediator(item.msg.sender_role)" class="size-3" />
                  <span v-else class="text-[9px] font-bold">C</span>
                </div>

                <div
                  class="max-w-[min(100%,26rem)] overflow-hidden rounded-[14px] shadow-[0_1px_2px_rgba(15,23,42,0.06)]"
                  :class="
                    isOutbound(item.msg.sender_role)
                      ? 'rounded-br-md bg-brand text-white'
                      : 'rounded-bl-md border border-slate-200/70 bg-white text-slate-900'
                  "
                >
                  <div
                    v-if="imageAttachments(item.msg).length"
                    class="grid gap-0.5 p-0.5"
                    :class="imageGridClass(imageAttachments(item.msg).length)"
                  >
                    <button
                      v-for="(file, idx) in imageAttachments(item.msg)"
                      :key="file.filename"
                      type="button"
                      class="group relative overflow-hidden bg-slate-100/80 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                      :class="
                        imageAttachments(item.msg).length === 1
                          ? 'min-h-[10rem] rounded-[12px]'
                          : 'aspect-square min-h-[5.5rem] rounded-[10px]'
                      "
                      @click="openLightbox(item.msg, idx)"
                    >
                      <img
                        v-if="!attachmentFailed(item.msg.id, file.filename)"
                        :src="attachmentUrl(file.filename)"
                        :alt="file.original_filename || file.filename || 'Adjunto'"
                        class="h-full w-full object-cover transition group-hover:brightness-95"
                        loading="lazy"
                        @error="markAttachmentFailed(item.msg.id, file.filename)"
                      />
                      <span
                        v-else
                        class="flex h-full min-h-[5.5rem] items-center justify-center px-2 text-center text-[10px] text-slate-500"
                      >
                        No se pudo cargar
                      </span>
                    </button>
                  </div>

                  <div
                    class="px-2.5 py-1.5"
                    :class="imageAttachments(item.msg).length ? 'pt-1' : ''"
                  >
                    <div
                      v-if="item.msg.message"
                      class="message-body text-[12px] leading-snug break-words"
                      :class="
                        isOutbound(item.msg.sender_role)
                          ? 'message-body--outbound'
                          : 'message-body--inbound'
                      "
                      v-html="formatOrderMessageHtml(item.msg.message)"
                    />
                    <ul
                      v-if="fileAttachments(item.msg).length"
                      class="mt-1.5 space-y-0.5 text-[10px]"
                      :class="
                        isOutbound(item.msg.sender_role)
                          ? 'text-white/85'
                          : 'text-muted-foreground'
                      "
                    >
                      <li
                        v-for="file in fileAttachments(item.msg)"
                        :key="file.filename"
                      >
                        <a
                          :href="attachmentUrl(file.filename)"
                          class="inline-flex max-w-full items-center gap-1 truncate underline-offset-2 hover:underline"
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <Paperclip class="size-2.5 shrink-0" />
                          <span class="truncate">
                            {{ file.original_filename || file.filename || 'Adjunto' }}
                          </span>
                        </a>
                      </li>
                    </ul>
                    <p
                      class="mt-1 text-[10px]"
                      :class="
                        isOutbound(item.msg.sender_role)
                          ? 'text-white/65'
                          : 'text-muted-foreground'
                      "
                      :title="item.msg.sent_at ? formatDateTime(item.msg.sent_at) : ''"
                    >
                      <template v-if="item.msg.sent_at">
                        {{ formatRelativeShort(item.msg.sent_at) }}
                      </template>
                      <template v-else>—</template>
                      · {{ roleLabel(item.msg.sender_role) }}
                    </p>
                  </div>
                </div>
              </div>
            </li>
          </ul>
        </template>
      </div>

      <div class="mt-auto border-t border-slate-100 pt-2">
        <template v-if="canMessageMediator">
          <p
            v-if="statusDescription"
            class="mb-1.5 text-center text-[10px] text-muted-foreground"
          >
            {{ statusDescription }}
          </p>
          <div class="flex items-end gap-1.5">
            <textarea
              v-model="draft"
              rows="1"
              class="min-h-9 flex-1 resize-none rounded-[14px] border border-slate-200/80 bg-white px-2.5 py-2 text-[12px] shadow-[0_1px_2px_rgba(15,23,42,0.04)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
              placeholder="Escribe tu mensaje…"
              maxlength="3500"
              @keydown.meta.enter.prevent="send"
              @keydown.ctrl.enter.prevent="send"
            />
            <Button
              type="button"
              size="icon"
              class="size-8 shrink-0 rounded-full"
              :disabled="sending || loading || !draft.trim()"
              @click="send"
            >
              <SendHorizontal class="size-3.5" />
            </Button>
          </div>
          <div class="mt-1 flex items-center justify-between gap-2 px-0.5">
            <span class="text-[10px] text-muted-foreground">
              {{ draft.length }}/3500
            </span>
            <span class="text-[10px] text-muted-foreground">⌘/Ctrl + Enter</span>
          </div>
          <p v-if="sendError" class="mt-1.5 text-xs text-red-600">{{ sendError }}</p>
        </template>
        <div
          v-else
          class="rounded-xl bg-slate-50 px-3 py-2 text-center text-[11px] text-muted-foreground"
        >
          No hay acciones de mensajería disponibles en este reclamo por ahora.
        </div>
      </div>
    </template>

    <ClaimAttachmentLightbox
      :open="lightboxOpen"
      :images="lightboxImages"
      :initial-index="lightboxIndex"
      @close="closeLightbox"
      @update:index="(value) => (lightboxIndex = value)"
    />
  </div>
</template>

<style scoped>
.message-body :deep(a) {
  font-weight: 500;
  text-decoration: underline;
  text-underline-offset: 2px;
  word-break: break-word;
}

.message-body--outbound :deep(a) {
  color: #fff;
}

.message-body--inbound :deep(a) {
  color: var(--color-brand, #0f766e);
}

.message-body :deep(br) {
  display: block;
  content: '';
  margin-top: 0.2rem;
}
</style>
