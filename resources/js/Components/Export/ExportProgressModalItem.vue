<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import Button from '@/Components/ui/Button.vue'

const props = defineProps({
  item: { type: Object, required: true },
})

const emit = defineEmits(['close', 'preview', 'update'])

const polling = ref(null)
const local = ref({ ...props.item })

watch(
  () => props.item,
  (v) => {
    local.value = { ...local.value, ...v }
  },
  { deep: true },
)

const statusLabel = computed(() => {
  const s = local.value.status
  if (s === 'stalled') return 'Atascado'
  if (s === 'completed') return 'Listo'
  if (s === 'error' || s === 'failed') return 'Error'
  if (s === 'cancelled') return 'Cancelado'
  if (s === 'processing') return 'Procesando'
  return 'En cola'
})

async function poll() {
  if (!local.value.token || String(local.value.token).startsWith('pending-')) return
  try {
    const { data } = await axios.get(route('exports.status'), {
      params: { token: local.value.token },
    })
    local.value = { ...local.value, ...data }
    emit('update', { ...local.value })
    if (['completed', 'error', 'failed', 'cancelled'].includes(data.status)) {
      stopPoll()
    }
  } catch {
    // keep polling; worker may be down
  }
}

function startPoll() {
  stopPoll()
  poll()
  polling.value = setInterval(poll, 1500)
}

function stopPoll() {
  if (polling.value) {
    clearInterval(polling.value)
    polling.value = null
  }
}

async function cancel() {
  if (!local.value.token || String(local.value.token).startsWith('pending-')) {
    emit('close')
    return
  }
  await axios.post(route('exports.cancel'), { token: local.value.token })
  await poll()
}

async function resume() {
  await axios.post(route('exports.resume'), { token: local.value.token })
  startPoll()
}

function download() {
  if (local.value.download_url) {
    window.location.href = local.value.download_url
  }
}

onMounted(startPoll)
onUnmounted(stopPoll)

watch(
  () => local.value.token,
  () => startPoll(),
)
</script>

<template>
  <div class="w-80 rounded-lg border border-slate-200 bg-white p-4 shadow-lg">
    <div class="mb-2 flex items-start justify-between gap-2">
      <div>
        <p class="text-sm font-semibold text-slate-900">
          {{ local.modalMessage || local.target_module || 'Exportación' }}
        </p>
        <p class="text-xs text-slate-500">{{ statusLabel }}</p>
      </div>
      <button
        type="button"
        class="text-slate-400 hover:text-slate-700"
        @click="emit('close')"
      >
        ×
      </button>
    </div>

    <div class="mb-2 h-2 overflow-hidden rounded bg-slate-100">
      <div
        class="h-full bg-sky-500 transition-all"
        :style="{ width: `${Math.min(100, local.progress || 0)}%` }"
      />
    </div>
    <p class="mb-3 text-xs text-slate-500">
      {{ local.progress || 0 }}%
      <span v-if="local.estimated_time"> · {{ local.estimated_time }}</span>
      <span v-if="local.total_rows != null"> · {{ local.total_rows }} filas</span>
    </p>

    <p
      v-if="local.error"
      class="mb-2 text-xs text-rose-600"
    >
      {{ local.error }}
    </p>

    <div class="flex flex-wrap gap-2">
      <Button
        v-if="local.status === 'completed'"
        size="sm"
        @click="download"
      >
        Descargar
      </Button>
      <Button
        v-if="local.status === 'completed'"
        size="sm"
        variant="outline"
        @click="emit('preview', local.token)"
      >
        Preview
      </Button>
      <Button
        v-if="['queued', 'pending', 'processing', 'stalled'].includes(local.status)"
        size="sm"
        variant="outline"
        @click="cancel"
      >
        Cancelar
      </Button>
      <Button
        v-if="local.status === 'stalled' || local.status === 'error'"
        size="sm"
        variant="outline"
        @click="resume"
      >
        Reanudar
      </Button>
    </div>
  </div>
</template>
