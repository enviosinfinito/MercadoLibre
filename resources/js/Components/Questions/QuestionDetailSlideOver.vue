<script setup>
import { computed, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime, formatElapsedDuration, formatRelativeShort } from '@/lib/utils'
import { ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS } from '@/lib/slideOverLayout'

const props = defineProps({
  show: { type: Boolean, default: false },
  questionId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close'])

const loading = ref(false)
const answering = ref(false)
const error = ref(null)
const answerError = ref(null)
const payload = ref(null)
const answerText = ref('')

const question = computed(() => payload.value?.question ?? null)
const listing = computed(() => payload.value?.listing ?? null)

const statusLabel = computed(() => matchStatus(question.value?.status))

const statusBadgeVariant = computed(() => {
  const status = question.value?.status
  if (status === 'unanswered') return 'warning'
  if (status === 'answered') return 'success'
  return 'secondary'
})

const canAnswer = computed(() => {
  const status = question.value?.status
  return status === 'unanswered' || status === 'closed_unanswered'
})

const buyerLabel = computed(() => {
  const from = question.value?.meta?.from
  const id = question.value?.buyer_external_id
  if (from?.nickname) return from.nickname
  if (id) return `Comprador #${id}`
  return '—'
})

const responseDuration = computed(() =>
  formatElapsedDuration(question.value?.asked_at, question.value?.answered_at),
)

function matchStatus(status) {
  return (
    {
      unanswered: 'Sin responder',
      answered: 'Respondida',
      closed_unanswered: 'Cerrada sin respuesta',
      under_review: 'En revisión',
      banned: 'Bloqueada',
      deleted: 'Eliminada',
    }[status] ?? status ?? '—'
  )
}

function getXsrfToken() {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  answerError.value = null
  payload.value = null
  answerText.value = ''
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('questions.show', id)
        : `/questions/${id}`
    payload.value = await fetchSlidePayload(url, { cache: 'no-store' })
  } catch (e) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo cargar la pregunta.'
  } finally {
    loading.value = false
  }
}

async function submitAnswer() {
  if (!question.value?.id || answering.value) return
  const text = answerText.value.trim()
  if (!text) {
    answerError.value = 'Escribe una respuesta.'
    return
  }
  answering.value = true
  answerError.value = null
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('questions.answer', question.value.id)
        : `/questions/${question.value.id}/answer`
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
    answerText.value = ''
  } catch (e) {
    answerError.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo enviar la respuesta.'
  } finally {
    answering.value = false
  }
}

watch(
  () => [props.show, props.questionId],
  ([open, id]) => {
    if (open && id) load(id)
    if (!open) {
      payload.value = null
      error.value = null
      answerError.value = null
      answerText.value = ''
    }
  },
)
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    compact-header
    fill-height
    mobile-full-bleed
    :max-width="ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS"
    accessibility-title="Detalle de pregunta"
    @close="emit('close')"
  >
    <template #header>
      <div class="min-w-0">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Pregunta</p>
        <h2 class="truncate text-base font-semibold text-slate-900">
          #{{ question?.external_question_id ?? questionId ?? '—' }}
        </h2>
      </div>
    </template>

    <div class="flex min-h-0 flex-1 flex-col overflow-auto px-4 py-4 sm:px-6">
      <div v-if="loading" class="flex flex-1 items-center justify-center py-16 text-sm text-neutral-500">
        Cargando pregunta…
      </div>
      <div
        v-else-if="error"
        class="flex flex-1 flex-col items-center justify-center gap-3 py-16 text-center"
      >
        <p class="text-sm text-neutral-700">{{ error }}</p>
        <button
          type="button"
          class="text-sm font-medium text-brand hover:text-brand-hover"
          @click="load(questionId)"
        >
          Reintentar
        </button>
      </div>
      <template v-else-if="question">
        <div class="space-y-6">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <Badge :variant="statusBadgeVariant">{{ statusLabel }}</Badge>
            <span
              v-if="question.asked_at"
              class="text-xs text-muted-foreground"
              :title="formatDateTime(question.asked_at)"
            >
              {{ formatRelativeShort(question.asked_at) }}
              · {{ formatDateTime(question.asked_at) }}
            </span>
          </div>

          <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5">
            <h3 class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              Pregunta
            </h3>
            <p class="whitespace-pre-wrap text-[15px] leading-relaxed text-slate-900">
              {{ question.question_text || '—' }}
            </p>
          </div>

          <div
            v-if="question.answer_text"
            class="rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-3.5"
          >
            <h3 class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-emerald-800/70">
              Respuesta
            </h3>
            <p class="whitespace-pre-wrap text-[15px] leading-relaxed text-slate-900">
              {{ question.answer_text }}
            </p>
            <p
              v-if="question.answered_at || responseDuration"
              class="mt-2 text-xs text-muted-foreground"
            >
              <span v-if="responseDuration" class="font-medium text-emerald-700">
                Respondida en {{ responseDuration }}
              </span>
              <span v-if="question.answered_at && responseDuration"> · </span>
              <span v-if="question.answered_at">{{ formatDateTime(question.answered_at) }}</span>
            </p>
          </div>

          <div v-else-if="canAnswer" class="space-y-2 rounded-xl border border-slate-200 p-4">
            <h3 class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              Responder
            </h3>
            <textarea
              v-model="answerText"
              rows="4"
              class="w-full rounded-md border border-input bg-white px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
              placeholder="Escribe tu respuesta…"
              maxlength="2000"
            />
            <p v-if="answerError" class="text-sm text-red-600">{{ answerError }}</p>
            <Button type="button" size="sm" :disabled="answering" @click="submitAnswer">
              {{ answering ? 'Enviando…' : 'Enviar respuesta' }}
            </Button>
          </div>

          <dl class="grid gap-4 sm:grid-cols-2">
            <div>
              <dt class="text-xs text-muted-foreground">Publicación</dt>
              <dd class="mt-1 text-sm">
                <template v-if="listing">
                  <a
                    v-if="listing.permalink"
                    :href="listing.permalink"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-brand hover:underline"
                  >
                    {{ listing.title || listing.external_item_id }}
                  </a>
                  <span v-else>{{ listing.title || listing.external_item_id }}</span>
                </template>
                <span v-else class="font-mono text-xs">
                  {{ question.external_item_id || '—' }}
                </span>
              </dd>
            </div>
            <div>
              <dt class="text-xs text-muted-foreground">Comprador</dt>
              <dd class="mt-1 text-sm">{{ buyerLabel }}</dd>
            </div>
          </dl>
        </div>
      </template>
    </div>
  </SlideOverShell>
</template>
