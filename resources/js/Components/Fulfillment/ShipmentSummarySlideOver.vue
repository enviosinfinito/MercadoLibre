<script setup>
import { computed, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime } from '@/lib/utils'

const props = defineProps({
  show: { type: Boolean, default: false },
  shipmentId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close', 'open-order'])

const loading = ref(false)
const error = ref(null)
const shipment = ref(null)

const meta = computed(() => shipment.value?.meta ?? {})
const history = computed(() => meta.value.status_history ?? {})
const address = computed(() => meta.value.receiver_address ?? null)
const receiver = computed(() => meta.value.receiver ?? null)

const receiverName = computed(() => {
  const fromReceiver =
    receiver.value?.name ??
    receiver.value?.receiver_name ??
    address.value?.receiver_name ??
    address.value?.name ??
    null
  return fromReceiver || null
})

const formattedAddress = computed(() => {
  const a = address.value
  if (!a || typeof a !== 'object') return null
  const parts = [
    a.address_line,
    a.street_name && a.street_number ? `${a.street_name} ${a.street_number}` : a.street_name,
    a.neighborhood?.name,
    a.city?.name,
    a.state?.name,
    a.zip_code,
    a.country?.name,
  ].filter(Boolean)
  return parts.length ? [...new Set(parts)].join(', ') : null
})

const historyRows = computed(() => {
  const h = history.value
  if (!h || typeof h !== 'object') return []
  const labels = {
    date_ready_to_ship: 'Listo para envío',
    date_handling: 'En preparación',
    date_shipped: 'Enviado',
    date_first_visit: 'Primera visita',
    date_delivered: 'Entregado',
    date_not_delivered: 'No entregado',
    date_cancelled: 'Cancelado',
    date_returned: 'Devuelto',
  }
  return Object.entries(labels)
    .filter(([key]) => h[key])
    .map(([key, label]) => ({ key, label, at: h[key] }))
})

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  shipment.value = null
  try {
    const url = typeof window !== 'undefined' && window.route
      ? window.route('shipments.show', id)
      : `/shipments/${id}`
    const data = await fetchSlidePayload(url, { cache: 'no-store' })
    shipment.value = data.shipment
  } catch (e) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar el envío.'
  } finally {
    loading.value = false
  }
}

function retryFetch() {
  load(props.shipmentId)
}

watch(
  () => [props.show, props.shipmentId],
  ([open, id]) => {
    if (open && id) load(id)
    if (!open) {
      shipment.value = null
      error.value = null
    }
  },
  { immediate: true },
)
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    mobile-full-bleed
    fill-height
    :loading="loading"
    :error="error"
    accessibility-title="Detalle de envío"
    @close="emit('close')"
    @retry="retryFetch"
  >
    <div v-if="shipment" class="flex min-h-0 flex-1 flex-col overflow-auto px-4 py-4 sm:px-6">
      <div class="mb-4">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Envío</p>
        <h2 class="text-lg font-semibold text-slate-900">
          #{{ shipment.external_shipment_id ?? shipment.id }}
        </h2>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <Badge variant="secondary" class="capitalize">{{ shipment.status }}</Badge>
          <Badge v-if="meta.substatus" variant="outline" class="capitalize">
            {{ meta.substatus }}
          </Badge>
        </div>
      </div>

      <dl class="grid gap-4 sm:grid-cols-2">
        <div>
          <dt class="text-xs text-muted-foreground">Mensajería</dt>
          <dd class="mt-1 text-sm">
            {{ shipment.carrier || meta.tracking_method || '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Número de rastreo</dt>
          <dd class="mt-1 font-mono text-sm break-all">
            {{ shipment.tracking_number ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Modo logístico</dt>
          <dd class="mt-1 text-sm capitalize">
            {{ meta.logistic_type || meta.mode || '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Service ID</dt>
          <dd class="mt-1 font-mono text-sm">{{ meta.service_id ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Enviado</dt>
          <dd class="mt-1 text-sm">
            {{ shipment.shipped_at ? formatDateTime(shipment.shipped_at) : '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Entregado</dt>
          <dd class="mt-1 text-sm">
            {{ shipment.delivered_at ? formatDateTime(shipment.delivered_at) : '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Creado en ML</dt>
          <dd class="mt-1 text-sm">
            {{ meta.date_created ? formatDateTime(meta.date_created) : '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Última actualización</dt>
          <dd class="mt-1 text-sm">
            {{ meta.last_updated ? formatDateTime(meta.last_updated) : '—' }}
          </dd>
        </div>
        <div v-if="meta.base_cost != null">
          <dt class="text-xs text-muted-foreground">Costo base envío</dt>
          <dd class="mt-1 text-sm">{{ meta.base_cost }}</dd>
        </div>
        <div v-if="shipment.order">
          <dt class="text-xs text-muted-foreground">Orden</dt>
          <dd class="mt-1 text-sm">
            #{{ shipment.order.external_order_id ?? shipment.order.id }}
          </dd>
        </div>
      </dl>

      <section class="mt-6 space-y-3">
        <h3 class="text-sm font-semibold text-slate-900">Receptor</h3>
        <dl class="grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-xs text-muted-foreground">Quién recibe</dt>
            <dd class="mt-1 text-sm">{{ receiverName || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted-foreground">Receiver ID</dt>
            <dd class="mt-1 font-mono text-sm">{{ meta.receiver_id ?? '—' }}</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-muted-foreground">Dirección</dt>
            <dd class="mt-1 text-sm">{{ formattedAddress || '—' }}</dd>
          </div>
          <div v-if="address?.comment" class="sm:col-span-2">
            <dt class="text-xs text-muted-foreground">Referencia</dt>
            <dd class="mt-1 text-sm">{{ address.comment }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="historyRows.length" class="mt-6 space-y-3">
        <h3 class="text-sm font-semibold text-slate-900">Historial de estados</h3>
        <ol class="space-y-2">
          <li
            v-for="row in historyRows"
            :key="row.key"
            class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2"
          >
            <span class="text-sm font-medium text-slate-800">{{ row.label }}</span>
            <span class="text-right text-xs text-slate-500">{{ formatDateTime(row.at) }}</span>
          </li>
        </ol>
      </section>
    </div>

    <template #footer>
      <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
        <template #end>
          <ActionGroup>
            <ActionButton
              v-if="shipment?.order_id"
              label="Ver orden"
              variant="secondary"
              @click="emit('open-order', shipment.order_id)"
            />
            <ActionButton label="Cerrar" variant="brand" @click="emit('close')" />
          </ActionGroup>
        </template>
      </ActionBar>
    </template>
  </SlideOverShell>
</template>
