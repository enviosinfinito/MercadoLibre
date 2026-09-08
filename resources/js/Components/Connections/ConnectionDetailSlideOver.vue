<script setup>
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import ConnectionDetailPanel from '@/Components/Connections/ConnectionDetailPanel.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'

const ConnectionAccountProfileSlideOver = defineAsyncComponent(
  () => import('@/Components/Connections/ConnectionAccountProfileSlideOver.vue'),
)
const ConnectionAccountStatusSlideOver = defineAsyncComponent(
  () => import('@/Components/Connections/ConnectionAccountStatusSlideOver.vue'),
)
const ConnectionReputationDetailSlideOver = defineAsyncComponent(
  () => import('@/Components/Connections/ConnectionReputationDetailSlideOver.vue'),
)
const ConnectionPurchaseExperienceSlideOver = defineAsyncComponent(
  () => import('@/Components/Connections/ConnectionPurchaseExperienceSlideOver.vue'),
)

const props = defineProps({
  show: { type: Boolean, default: false },
  connectionId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close'])

const loading = ref(false)
const error = ref(null)
const payload = ref(null)
const openSection = ref(null)

const platformTitle = computed(() => {
  const provider = payload.value?.connection?.provider
  const platforms = payload.value?.platforms ?? []
  const match = platforms.find((p) => p.id === provider)
  return match?.name ?? provider ?? 'Conexión'
})

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('connections.show', id)
        : `/connections/${id}`
    payload.value = await fetchSlidePayload(url, { cache: 'no-store' })
  } catch (e) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo cargar la conexión.'
    payload.value = null
  } finally {
    loading.value = false
  }
}

function retry() {
  load(props.connectionId)
}

function onOpenAccountSection(section) {
  openSection.value = section
}

function closeAccountSection() {
  openSection.value = null
}

watch(
  () => [props.show, props.connectionId],
  ([open, id]) => {
    if (open && id) {
      load(id)
    }
    if (!open) {
      payload.value = null
      error.value = null
      openSection.value = null
    }
  },
  { immediate: true },
)
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    compact-header
    fill-height
    mobile-full-bleed
    :loading="loading"
    :error="error"
    :accessibility-title="`Sync · ${platformTitle}`"
    @close="emit('close')"
    @retry="retry"
  >
    <template #header>
      <p class="truncate text-[13px] font-semibold tracking-tight text-slate-900">
        Sync · {{ platformTitle }}
      </p>
    </template>

    <ConnectionDetailPanel
      v-if="payload?.connection"
      :connection="payload.connection"
      :catalog="payload.catalog ?? []"
      :profiles="payload.profiles ?? []"
      :platforms="payload.platforms ?? []"
      :reputation-timeline="payload.reputation_timeline"
      :purchase-experience-summary="payload.purchase_experience_summary"
      compact
      @open-account-section="onOpenAccountSection"
    />
  </SlideOverShell>

  <ConnectionAccountProfileSlideOver
    :show="show && openSection === 'profile'"
    :connection="payload?.connection"
    @close="closeAccountSection"
  />
  <ConnectionAccountStatusSlideOver
    :show="show && openSection === 'status'"
    :connection="payload?.connection"
    @close="closeAccountSection"
  />
  <ConnectionReputationDetailSlideOver
    :show="show && openSection === 'reputation'"
    :connection="payload?.connection"
    :reputation-timeline="payload?.reputation_timeline"
    @close="closeAccountSection"
  />
  <ConnectionPurchaseExperienceSlideOver
    :show="show && openSection === 'purchase_experience'"
    :connection="payload?.connection"
    :purchase-experience-summary="payload?.purchase_experience_summary"
    :purchase-experience-listings="payload?.purchase_experience_listings ?? []"
    @close="closeAccountSection"
  />
</template>
