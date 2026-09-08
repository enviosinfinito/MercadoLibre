<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime, formatRelativeShort } from '@/lib/utils'
import {
  conversationStatusLabel,
  conversationStatusVariant,
  formatOrderMessageHtml,
} from '@/lib/formatOrderMessage'
import { RefreshCw } from 'lucide-vue-next'

const props = defineProps({
  orderId: { type: [Number, String], required: true },
  active: { type: Boolean, default: false },
  activeClaim: { type: Object, default: null },
})

const emit = defineEmits(['stats', 'open-claims'])

const loading = ref(false)
const sending = ref(false)
const error = ref(null)
const warning = ref(null)
const sendError = ref(null)
const payload = ref(null)
const draft = ref('')
const listEl = ref(null)

const messages = computed(() => payload.value?.messages ?? [])
const maxLength = computed(() => payload.value?.seller_max_message_length ?? 350)
const conversationStatus = computed(
  () => payload.value?.conversation_status?.status ?? null,
)
const buyerInboundCount = computed(
  () => messages.value.filter((m) => m.direction === 'inbound').length,
)

const hasOpenClaim = computed(() => props.activeClaim?.status === 'opened')
const conversationBlocked = computed(() => {
  const status = conversationStatus.value
  return (
    hasOpenClaim.value ||
    status === 'blocked' ||
    status === 'disabled' ||
    status === 'closed'
  )
})

const reputationLabel = computed(() => {
  const value = props.activeClaim?.affects_reputation
  if (value === 'not_affected') return 'No afectó tu reputación'
  if (value === 'affected') return 'Afectó tu reputación'
  if (value === 'not_applies') return 'No aplica a reputación'
  return null
})

const claimBannerTitle = computed(() => {
  const claim = props.activeClaim
  if (!claim) return ''
  const id = claim.external_claim_id ?? claim.id
  const stage = claim.stage
  const prefix =
    stage === 'dispute' || stage === 'recontact'
      ? 'Mediación iniciada'
      : claim.status === 'opened'
        ? 'Reclamo abierto'
        : 'Reclamo'
  const rep = reputationLabel.value
  return rep ? `${prefix} n.º ${id} | ${rep}` : `${prefix} n.º ${id}`
})

const claimReasonText = computed(() => {
  const claim = props.activeClaim
  if (!claim) return null
  return claim.problem || claim.reason_detail || claim.reason || claim.reason_id || null
})

function emitStats() {
  emit('stats', {
    buyerInboundCount: buyerInboundCount.value,
    totalCount: messages.value.length,
  })
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

async function load(id, { refresh = true } = {}) {
  if (!id) return
  loading.value = true
  error.value = null
  warning.value = null
  try {
    const base =
      typeof window !== 'undefined' && window.route
        ? window.route('orders.messages', id)
        : `/orders/${id}/messages`
    const url = refresh ? base : `${base}?cached_only=1`
    payload.value = await fetchSlidePayload(url, { cache: 'no-store' })
    warning.value = payload.value?.warning ?? null
    emitStats()
    await scrollToBottom()
  } catch (e) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudieron cargar los mensajes.'
  } finally {
    loading.value = false
  }
}

async function send() {
  if (!props.orderId || sending.value || conversationBlocked.value) return
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
        ? window.route('orders.messages.send', props.orderId)
        : `/orders/${props.orderId}/messages`
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
    emitStats()
    await scrollToBottom()
  } catch (e) {
    sendError.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo enviar el mensaje.'
  } finally {
    sending.value = false
  }
}

watch(
  () => [props.active, props.orderId],
  ([active, id]) => {
    if (active && id) {
      load(id)
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
  <div class="flex min-h-[28rem] flex-1 flex-col">
    <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-2">
      <div class="flex min-w-0 flex-wrap items-center gap-1.5">
        <p class="text-[13px] font-semibold tracking-tight text-slate-900">
          Mensajes con el comprador
        </p>
        <Badge
          v-if="conversationStatus"
          :variant="conversationStatusVariant(conversationStatus)"
          class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
        >
          {{ conversationStatusLabel(conversationStatus) }}
        </Badge>
        <span class="text-[11px] text-muted-foreground">
          {{ messages.length }} {{ messages.length === 1 ? 'mensaje' : 'mensajes' }}
        </span>
      </div>
      <Button
        type="button"
        size="icon"
        variant="outline"
        class="size-7 shrink-0 rounded-full"
        :disabled="loading"
        title="Actualizar"
        @click="load(orderId)"
      >
        <RefreshCw class="size-3.5" :class="{ 'animate-spin': loading }" />
      </Button>
    </div>

    <div ref="listEl" class="min-h-0 flex-1 overflow-auto py-2.5">
      <div v-if="loading" class="flex justify-center py-12 text-xs text-neutral-500">
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
          @click="load(orderId)"
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
        <p v-if="messages.length === 0" class="text-xs text-muted-foreground">
          No hay mensajes postventa en esta orden todavía.
        </p>
        <ul v-else class="space-y-2">
          <li
            v-for="msg in messages"
            :key="msg.id"
            class="flex"
            :class="msg.direction === 'outbound' ? 'justify-end' : 'justify-start'"
          >
            <div
              class="max-w-[85%] rounded-[14px] px-2.5 py-1.5 shadow-[0_1px_2px_rgba(15,23,42,0.06)]"
              :class="
                msg.direction === 'outbound'
                  ? 'rounded-br-md bg-brand text-white'
                  : 'rounded-bl-md bg-slate-100 text-slate-900'
              "
            >
              <div
                class="message-body text-[12px] leading-snug break-words"
                :class="
                  msg.direction === 'outbound'
                    ? 'message-body--outbound'
                    : 'message-body--inbound'
                "
                v-html="formatOrderMessageHtml(msg.text)"
              />
              <p
                class="mt-1 text-[10px]"
                :class="msg.direction === 'outbound' ? 'text-white/70' : 'text-muted-foreground'"
                :title="msg.sent_at ? formatDateTime(msg.sent_at) : ''"
              >
                <template v-if="msg.sent_at">
                  {{ formatRelativeShort(msg.sent_at) }}
                </template>
                <template v-else>—</template>
                · {{ msg.direction === 'outbound' ? 'Tú' : 'Comprador' }}
              </p>
            </div>
          </li>
        </ul>

        <div v-if="activeClaim" class="mt-4">
          <div class="relative flex items-center justify-center py-1">
            <div class="absolute inset-x-0 top-1/2 h-px bg-slate-200" />
            <span
              class="relative inline-flex size-5 items-center justify-center rounded-full bg-brand text-[10px] font-semibold text-white shadow-sm"
            >
              !
            </span>
          </div>
          <button
            type="button"
            class="group mt-1.5 w-full rounded-xl border border-slate-200/70 bg-slate-50/80 px-3 py-2 text-left shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-colors hover:border-brand/40 hover:bg-brand/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
            @click="emit('open-claims')"
          >
            <p class="text-[12px] font-semibold leading-snug tracking-tight text-slate-900">
              {{ claimBannerTitle }}
            </p>
            <p
              v-if="claimReasonText"
              class="mt-1 text-[11px] leading-snug text-slate-600"
            >
              {{ claimReasonText }}
            </p>
            <p class="mt-1.5 text-[11px] font-semibold text-brand group-hover:underline">
              Ver mediación →
            </p>
          </button>
        </div>
      </template>
    </div>

    <div class="border-t border-slate-100 pt-2">
      <template v-if="conversationBlocked">
        <div class="rounded-xl border border-slate-200/70 bg-slate-50 px-3 py-2.5 text-center">
          <p class="text-[12px] font-semibold tracking-tight text-slate-900">
            Esta conversación está deshabilitada porque tienes una mediación abierta.
          </p>
          <button
            v-if="activeClaim"
            type="button"
            class="mt-1 text-[11px] font-semibold text-brand hover:underline"
            @click="emit('open-claims')"
          >
            Ver mediación
          </button>
        </div>
      </template>
      <template v-else>
        <textarea
          v-model="draft"
          rows="2"
          class="min-h-9 w-full resize-none rounded-[14px] border border-slate-200/80 bg-white px-2.5 py-2 text-[12px] shadow-[0_1px_2px_rgba(15,23,42,0.04)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
          :placeholder="`Escribe un mensaje (máx. ${maxLength})…`"
          :maxlength="maxLength"
          @keydown.meta.enter.prevent="send"
          @keydown.ctrl.enter.prevent="send"
        />
        <div class="mt-1.5 flex items-center justify-between gap-2">
          <span class="text-[10px] text-muted-foreground">
            {{ draft.length }}/{{ maxLength }}
          </span>
          <Button
            type="button"
            size="sm"
            class="h-7 px-2.5 text-[11px]"
            :disabled="sending || loading || !draft.trim()"
            @click="send"
          >
            {{ sending ? 'Enviando…' : 'Enviar' }}
          </Button>
        </div>
        <p v-if="sendError" class="mt-1.5 text-xs text-red-600">{{ sendError }}</p>
      </template>
    </div>
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
