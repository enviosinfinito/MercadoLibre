<script setup>
import { computed, ref, watch } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import {
  buildCurlCommand,
  directionBadgeClass,
  directionLabel,
  getTypeTag,
  getTypeTagClass,
  latencyMeta,
} from '@/composables/useSyncHttpLogs'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import { formatFriendlyDateTime } from '@/lib/utils'

const props = defineProps({
  log: { type: Object, required: true },
  variant: { type: String, default: 'slide' },
})

const emit = defineEmits(['open-connection'])

const tab = ref('request')

const baseTabs = [
  { key: 'request', label: 'Request' },
  { key: 'response', label: 'Response' },
  { key: 'headers', label: 'Headers' },
]

const tabs = computed(() => {
  const list = [...baseTabs]
  if (props.log?.error_redacted) {
    list.push({ key: 'error', label: 'Error' })
  }
  if (props.log?.direction === 'out') {
    list.push({ key: 'curl', label: 'cURL' })
  }
  return list
})

watch(
  () => props.log?.id,
  () => {
    tab.value = 'request'
  },
)

function formatBody(raw) {
  if (!raw) return '—'
  try {
    return JSON.stringify(JSON.parse(raw), null, 2)
  } catch {
    return String(raw)
  }
}

const activeBody = computed(() => {
  const log = props.log
  if (!log) return ''
  if (tab.value === 'request') return formatBody(log.request_body_redacted)
  if (tab.value === 'response') return formatBody(log.response_body_redacted)
  if (tab.value === 'error') return formatBody(log.error_redacted)
  if (tab.value === 'curl') return buildCurlCommand(log) || '—'
  return JSON.stringify(
    {
      request: log.request_headers_redacted,
      response: log.response_headers_redacted,
    },
    null,
    2,
  )
})

const activeChars = computed(() => (activeBody.value && activeBody.value !== '—' ? activeBody.value.length : 0))

function statusVariant(status) {
  if (status == null) return 'muted'
  if (status >= 200 && status < 400) return 'success'
  return 'danger'
}

async function copyBody() {
  try {
    await navigator.clipboard.writeText(activeBody.value || '')
  } catch {
    // ignore
  }
}

function downloadBody() {
  const blob = new Blob([activeBody.value || ''], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `sync-log-${props.log?.id ?? 'x'}-${tab.value}.json`
  a.click()
  URL.revokeObjectURL(url)
}

async function copyMeta(value) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(String(value))
  } catch {
    // ignore
  }
}

const latency = computed(() => latencyMeta(props.log?.latency_ms))
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col" :class="variant === 'page' ? 'max-w-5xl' : ''">
    <div class="border-b border-border px-4 py-4 sm:px-6">
      <div class="flex flex-wrap items-center gap-2 font-mono text-xs text-muted-foreground">
        <span :title="log.created_at ?? undefined">{{ formatFriendlyDateTime(log.created_at) }}</span>
        <span class="text-neutral-300">·</span>
        <span class="inline-flex items-center gap-1">
          <span class="inline-block size-1.5 rounded-full" :class="latency.dotClass" />
          {{ log.latency_ms != null ? `${log.latency_ms}ms` : '—' }}
          <span class="text-neutral-400">({{ latency.label }})</span>
        </span>
        <span :class="getTypeTagClass(log)" class="font-semibold">{{ getTypeTag(log) }}</span>
        <span
          class="inline-flex h-4 items-center rounded px-1 text-[10px] font-semibold"
          :class="directionBadgeClass(log.direction)"
        >
          {{ directionLabel(log.direction) }}
        </span>
        <Badge :variant="statusVariant(log.response_status)" class="h-5 rounded px-1.5 text-[10px]">
          {{ log.response_status ?? '—' }}
        </Badge>
      </div>

      <h2 class="mt-2 break-all font-mono text-sm font-semibold text-slate-900">
        {{ log.method }} {{ log.url }}
      </h2>

      <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-neutral-500">
        <button
          v-if="log.correlation_id"
          type="button"
          class="hover:text-neutral-800"
          title="Copiar correlation"
          @click="copyMeta(log.correlation_id)"
        >
          corr: {{ log.correlation_id }}
        </button>
        <span v-if="log.sync_run_id">run: {{ log.sync_run_id }}</span>
        <span v-if="log.request_bytes != null || log.response_bytes != null">
          {{ log.request_bytes ?? '—' }}B → {{ log.response_bytes ?? '—' }}B
        </span>
        <button
          v-if="log.connection"
          type="button"
          class="inline-flex"
          @click="emit('open-connection', log.connection.id)"
        >
          <ConnectionChip
            :connection="log.connection"
            :account-only="false"
          />
        </button>
      </div>
    </div>

    <div class="flex gap-1 overflow-x-auto border-b border-border px-2 sm:px-4">
      <button
        v-for="t in tabs"
        :key="t.key"
        type="button"
        class="shrink-0 border-b-2 px-3 py-2.5 font-mono text-xs font-medium transition-colors"
        :class="
          tab === t.key
            ? 'border-emerald-600 text-slate-900'
            : 'border-transparent text-muted-foreground hover:text-slate-800'
        "
        @click="tab = t.key"
      >
        {{ t.label }}
      </button>
    </div>

    <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-2 font-mono text-xs text-muted-foreground sm:px-6">
      <span>{{ activeChars }} caracteres</span>
      <div class="flex gap-2">
        <Button size="sm" variant="outline" @click="copyBody">Copiar</Button>
        <Button size="sm" variant="outline" @click="downloadBody">Descargar</Button>
      </div>
    </div>

    <div class="min-h-0 flex-1 overflow-auto bg-slate-950 px-4 py-4 sm:px-6">
      <pre class="whitespace-pre-wrap break-all font-mono text-xs leading-relaxed text-emerald-100">{{
        activeBody
      }}</pre>
    </div>
  </div>
</template>
