<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import QuestionDetailSlideOver from '@/Components/Questions/QuestionDetailSlideOver.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime, formatElapsedDuration, formatRelativeShort } from '@/lib/utils'

const props = withDefaults(
  defineProps<{
    productId?: number | null
    mlItemId?: string | null
    connectionIds?: number[]
    active?: boolean
  }>(),
  {
    productId: null,
    mlItemId: null,
    connectionIds: () => [],
    active: true,
  },
)

const loading = ref(false)
const error = ref<string | null>(null)
const payload = ref<Record<string, any> | null>(null)
const questionOpen = ref(false)
const selectedQuestionId = ref<number | null>(null)

const summary = computed(() => payload.value?.summary || { total: 0, unanswered: 0 })
const channels = computed(() =>
  Array.isArray(payload.value?.channels) ? payload.value.channels : [],
)

function buildUrl() {
  const params = new URLSearchParams()
  if (props.productId) params.set('product_id', String(props.productId))
  if (props.mlItemId) params.set('ml_item_id', String(props.mlItemId))
  if (props.connectionIds?.length) {
    for (const id of props.connectionIds) {
      params.append('connection_ids[]', String(id))
    }
  }
  const base = route('catalog.product-questions')
  const qs = params.toString()
  return qs ? `${base}?${qs}` : base
}

async function load() {
  if (!props.productId && !props.mlItemId) {
    payload.value = null
    return
  }
  loading.value = true
  error.value = null
  try {
    payload.value = await fetchSlidePayload(buildUrl(), { cache: 'no-store' })
  } catch (e: any) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudieron cargar las preguntas.'
    payload.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.active, props.productId, props.mlItemId, props.connectionIds] as const,
  ([active]) => {
    if (active && (props.productId || props.mlItemId)) void load()
    if (!props.productId && !props.mlItemId) {
      payload.value = null
      error.value = null
    }
  },
  { immediate: true },
)

function statusLabel(status: string) {
  return (
    {
      unanswered: 'Sin responder',
      answered: 'Respondida',
      closed_unanswered: 'Cerrada sin respuesta',
      under_review: 'En revisión',
      banned: 'Bloqueada',
      deleted: 'Eliminada',
    }[status] ?? status
  )
}

function statusVariant(status: string) {
  if (status === 'unanswered' || status === 'closed_unanswered') return 'warning'
  if (status === 'answered') return 'success'
  return 'secondary'
}

function openQuestion(id: number) {
  selectedQuestionId.value = id
  questionOpen.value = true
}

function closeQuestion() {
  questionOpen.value = false
  selectedQuestionId.value = null
  if (props.active) void load()
}

function buyerLabel(row: Record<string, any>) {
  const from = row?.meta?.from
  if (from?.nickname) return from.nickname
  if (row?.buyer_external_id) return `Comprador #${row.buyer_external_id}`
  return null
}

function responseTimeLabel(row: Record<string, any>) {
  const duration = formatElapsedDuration(row?.asked_at, row?.answered_at)
  if (!duration) return null
  return `Respondida en ${duration}`
}
</script>

<template>
  <div class="space-y-3 p-3 sm:p-4">
    <div
      v-if="loading"
      class="py-16 text-center text-sm text-muted-foreground"
    >
      Cargando preguntas…
    </div>
    <div
      v-else-if="!productId && !mlItemId"
      class="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-muted-foreground"
    >
      No hay ítem de canal asociado para buscar preguntas.
    </div>
    <div
      v-else-if="error"
      class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800"
    >
      {{ error }}
      <button
        type="button"
        class="ml-2 font-semibold underline"
        @click="load"
      >
        Reintentar
      </button>
    </div>
    <template v-else-if="payload">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 class="text-[12px] font-semibold tracking-tight text-slate-900">
            Preguntas
          </h3>
          <p class="text-[10px] text-muted-foreground">
            Agrupadas por canal · más recientes primero
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
          <Badge variant="secondary">
            {{ summary.total }} total
          </Badge>
          <Badge
            v-if="summary.unanswered > 0"
            variant="warning"
          >
            {{ summary.unanswered }} sin responder
          </Badge>
        </div>
      </div>

      <div
        v-if="!channels.length"
        class="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-muted-foreground"
      >
        No hay preguntas para este producto.
      </div>

      <section
        v-for="channel in channels"
        :key="channel.connection?.id ?? 'none'"
        class="space-y-2"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <ConnectionChip
            v-if="channel.connection"
            :connection="channel.connection"
          />
          <div class="flex items-center gap-1.5 text-[10px] text-muted-foreground">
            <span>{{ channel.counts?.total ?? 0 }} pregunta{{ (channel.counts?.total ?? 0) === 1 ? '' : 's' }}</span>
            <span
              v-if="(channel.counts?.unanswered ?? 0) > 0"
              class="font-medium text-amber-700"
            >
              · {{ channel.counts.unanswered }} pendientes
            </span>
          </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200/70">
          <button
            v-for="row in channel.questions"
            :key="row.id"
            type="button"
            class="flex w-full flex-col gap-1 border-t border-slate-100 px-3 py-2.5 text-left transition hover:bg-slate-50/80 first:border-t-0"
            @click="openQuestion(row.id)"
          >
            <div class="flex items-start justify-between gap-2">
              <p class="line-clamp-2 min-w-0 text-[12px] font-medium text-slate-900">
                {{ row.question_text || '—' }}
              </p>
              <Badge
                :variant="statusVariant(row.status)"
                class="shrink-0 text-[10px]"
              >
                {{ statusLabel(row.status) }}
              </Badge>
            </div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] text-muted-foreground">
              <span
                v-if="buyerLabel(row)"
                class="truncate"
              >{{ buyerLabel(row) }}</span>
              <span
                v-if="row.asked_at"
                :title="formatDateTime(row.asked_at)"
              >
                {{ formatRelativeShort(row.asked_at) }}
              </span>
              <span
                v-if="responseTimeLabel(row)"
                class="font-medium text-emerald-700"
                :title="row.answered_at ? formatDateTime(row.answered_at) : undefined"
              >
                {{ responseTimeLabel(row) }}
              </span>
              <span
                v-if="row.external_item_id"
                class="font-mono tracking-tight"
              >{{ row.external_item_id }}</span>
            </div>
            <p
              v-if="row.answer_text"
              class="line-clamp-1 text-[11px] text-slate-600"
            >
              R: {{ row.answer_text }}
            </p>
          </button>
        </div>
      </section>
    </template>

    <QuestionDetailSlideOver
      :show="questionOpen"
      :question-id="selectedQuestionId ?? undefined"
      @close="closeQuestion"
    />
  </div>
</template>
