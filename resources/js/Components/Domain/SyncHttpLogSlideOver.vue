<script setup>
import { ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import LogDetailContent from '@/Components/SyncHttpLogs/LogDetailContent.vue'
import ConnectionDetailSlideOver from '@/Components/Connections/ConnectionDetailSlideOver.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'

const props = defineProps({
  show: { type: Boolean, default: false },
  logId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close'])

const loading = ref(false)
const error = ref(null)
const log = ref(null)
const connectionOpen = ref(false)
const connectionId = ref(null)

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  log.value = null
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('sync-logs.show', id)
        : `/sync-logs/${id}`
    const data = await fetchSlidePayload(url, { cache: 'no-store' })
    log.value = data.log
  } catch (e) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar el log.'
  } finally {
    loading.value = false
  }
}

function retryFetch() {
  load(props.logId)
}

watch(
  () => [props.show, props.logId],
  ([open, id]) => {
    if (open && id) load(id)
    if (!open) {
      log.value = null
      error.value = null
      connectionOpen.value = false
      connectionId.value = null
    }
  },
  { immediate: true },
)

function openConnection(id) {
  connectionId.value = id
  connectionOpen.value = true
}

async function copyId() {
  if (!log.value?.id) return
  try {
    await navigator.clipboard.writeText(String(log.value.id))
  } catch {
    // ignore
  }
}
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    mobile-full-bleed
    fill-height
    :loading="loading"
    :error="error"
    accessibility-title="Detalle de sync log"
    @close="emit('close')"
    @retry="retryFetch"
  >
    <LogDetailContent
      v-if="log"
      :log="log"
      variant="slide"
      @open-connection="openConnection"
    />

    <template #footer>
      <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
        <ActionGroup align="end">
          <ActionButton
            v-if="log?.id"
            variant="secondary"
            @click="copyId"
          >
            Copiar ID
          </ActionButton>
          <ActionButton variant="secondary" @click="emit('close')">Cerrar</ActionButton>
        </ActionGroup>
      </ActionBar>
    </template>
  </SlideOverShell>

  <ConnectionDetailSlideOver
    :show="connectionOpen"
    :connection-id="connectionId"
    @close="connectionOpen = false"
  />
</template>
