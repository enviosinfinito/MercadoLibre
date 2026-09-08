<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RefreshCw } from 'lucide-vue-next'
import Button from '@/Components/ui/Button.vue'
import Input from '@/Components/ui/Input.vue'
import { jsonFetch } from '@/lib/jsonFetch'

const props = defineProps({
  coverage: { type: Object, default: null },
  connections: { type: Array, default: () => [] },
  connectionId: { type: [Number, String], default: null },
})

const emit = defineEmits(['synced'])

const from = ref('')
const to = ref('')
const syncing = ref(false)
const message = ref(null)
const error = ref(null)
let pollTimer = null

watch(
  () => props.coverage,
  (c) => {
    from.value = c?.sync_from || ''
    to.value = c?.sync_to || ''
  },
  { immediate: true },
)

const targetConnectionId = computed(() => {
  if (props.connectionId) return Number(props.connectionId)
  const ids = props.coverage?.connection_ids || []
  if (ids.length) return Number(ids[0])
  return props.connections?.[0]?.id ? Number(props.connections[0].id) : null
})

const summary = computed(() => {
  const c = props.coverage
  if (!c) return null
  const parts = []
  const count = Number(c.overdue_count || 0)
  parts.push(`${count.toLocaleString('es-MX')} sin liberar`)
  if (c.tentative_from && c.tentative_to) {
    parts.push(`tentativa ${formatRange(c.tentative_from, c.tentative_to)}`)
  }
  if (c.covered_from && c.covered_to) {
    parts.push(`datos MP ${formatRange(c.covered_from, c.covered_to)}`)
  } else {
    parts.push('sin reportes MP de liberación')
  }
  const gaps = Array.isArray(c.gaps) ? c.gaps : []
  if (gaps.length) {
    const g = gaps[gaps.length - 1]
    parts.push(`falta ${formatRange(g.from, g.to)}${gaps.length > 1 ? ` (+${gaps.length - 1})` : ''}`)
  }
  return parts.join(' · ')
})

function formatRange(a, b) {
  if (!a) return '—'
  if (!b || a === b) return formatDay(a)
  return `${formatDay(a)} → ${formatDay(b)}`
}

function formatDay(value) {
  if (!value) return '—'
  try {
    return new Date(`${value}T12:00:00`).toLocaleDateString('es-MX', {
      day: 'numeric',
      month: 'short',
    })
  } catch {
    return value
  }
}

function getXsrfToken() {
  if (typeof document === 'undefined') return ''
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function pollStatus(connectionId) {
  try {
    const data = await jsonFetch(route('finance.cash.sync-status', { connection_id: connectionId }))
    if (data.progress?.active) {
      message.value = data.progress?.reports?.release?.message
        || data.progress?.reports?.settlement?.message
        || 'Sync en progreso…'
      return
    }
    stopPolling()
    syncing.value = false
    const reports = data.progress?.reports || {}
    const failed = Object.values(reports).some((r) => r?.phase === 'failed')
    message.value = failed
      ? 'Sync terminó con errores. Revisa Caja → CSV.'
      : 'Datos actualizados. Recarga para ver si siguen sin liberar.'
    emit('synced')
  } catch {
    // keep last message
  }
}

async function updateData() {
  const connectionId = targetConnectionId.value
  if (!connectionId) {
    error.value = 'Selecciona un canal para actualizar datos.'
    return
  }
  if (!from.value || !to.value) {
    error.value = 'Elige un rango Desde / Hasta.'
    return
  }
  error.value = null
  message.value = null
  syncing.value = true
  stopPolling()
  try {
    const data = await jsonFetch(route('finance.cash.sync'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      body: JSON.stringify({
        connection_id: connectionId,
        report_kind: 'settlement',
        from: from.value,
        to: to.value,
      }),
    })
    message.value = data.windows > 1
      ? `Encolados ${data.windows} tramos. Esperando reportes MP…`
      : 'Reportes encolados. Esperando MP…'
    pollTimer = setInterval(() => pollStatus(connectionId), 2500)
    await pollStatus(connectionId)
  } catch (e) {
    syncing.value = false
    error.value = e?.message ?? 'No se pudo actualizar el periodo'
  }
}

onBeforeUnmount(() => stopPolling())
</script>

<template>
  <div
    v-if="coverage && (coverage.overdue_count > 0 || coverage.gaps?.length)"
    class="mb-3 rounded-xl border border-rose-200/80 bg-rose-50/60 px-3 py-2.5"
  >
    <p class="text-[12px] leading-relaxed text-rose-900">
      {{ summary }}
    </p>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <Input
        v-model="from"
        type="date"
        class="h-8 max-w-[9.5rem] px-2.5 text-xs shadow-none"
      />
      <Input
        v-model="to"
        type="date"
        class="h-8 max-w-[9.5rem] px-2.5 text-xs shadow-none"
      />
      <Button
        type="button"
        size="sm"
        class="h-8 px-2.5 text-xs"
        :disabled="syncing || !targetConnectionId"
        @click="updateData"
      >
        <RefreshCw class="mr-1 size-3.5" :class="syncing ? 'animate-spin' : ''" />
        {{ syncing ? 'Actualizando…' : 'Actualizar datos' }}
      </Button>
      <span class="text-[10px] text-rose-800/80">
        Máx. {{ coverage.max_sync_days || 14 }} días por corrida · settlement + liberación
      </span>
    </div>
    <p v-if="message" class="mt-1.5 text-[11px] text-slate-700">{{ message }}</p>
    <p v-if="error" class="mt-1.5 text-[11px] text-rose-700">{{ error }}</p>
  </div>
</template>
