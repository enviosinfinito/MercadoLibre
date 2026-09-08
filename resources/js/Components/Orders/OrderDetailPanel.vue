<script setup>
import { computed, ref, watch } from 'vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import StackedData from '@/Components/App/StackedData.vue'
import FreshnessBadge from '@/Components/App/FreshnessBadge.vue'
import ProfitBreakdown from '@/Components/Domain/ProfitBreakdown.vue'
import OrderCashReconciliation from '@/Components/Domain/OrderCashReconciliation.vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import OrderMessagesPanel from '@/Components/Orders/OrderMessagesPanel.vue'
import OrderClaimsPanel from '@/Components/Orders/OrderClaimsPanel.vue'
import OrderInvoicePanel from '@/Components/Orders/OrderInvoicePanel.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { toCodeCase, toTitleCase } from '@/lib/formatDisplayText'
import { formatDateTime, formatRelativeShort } from '@/lib/utils'
import { connectionFilterLabel } from '@/lib/connectionLabel'

const props = defineProps({
  orderId: { type: [Number, String], required: true },
  selectedTab: { type: String, default: 'details' },
})

const emit = defineEmits([
  'update:selectedTab',
  'meta',
  'open-shipment',
  'open-timeline-step',
  'open-buyer',
  'open-product',
])

function lineProductPayload(line) {
  const productId = line?.product_id != null ? Number(line.product_id) : null
  const mlItemId =
    typeof line?.external_item_id === 'string' && line.external_item_id !== ''
      ? line.external_item_id
      : null
  if (productId == null && !mlItemId) return null
  return { productId, mlItemId }
}

function openLineProduct(line) {
  const payload = lineProductPayload(line)
  if (!payload) return
  emit('open-product', payload)
}

function lineTitle(line) {
  if (line?.title) return toTitleCase(line.title)
  if (line?.external_item_id) return toCodeCase(line.external_item_id)
  return '—'
}

const loading = ref(false)
const syncing = ref(false)
const error = ref(null)
const syncError = ref(null)
const order = ref(null)
const shipment = ref(null)
const timeline = ref([])
const buyerInboundCount = ref(0)
const claims = ref([])
const claimsOpenedCount = ref(0)
const claimsTotalCount = ref(0)
const postSale = ref(null)
const adsAttribution = ref(null)

const messagesTabActive = computed(() => props.selectedTab === 'messages')
const claimsTabActive = computed(() => props.selectedTab === 'claims')
const invoiceTabActive = computed(() => props.selectedTab === 'invoice')

const adsCurrency = computed(
  () => adsAttribution.value?.currency_code || order.value?.currency_code || 'MXN',
)
const adsEvents = computed(() =>
  Array.isArray(adsAttribution.value?.events) ? adsAttribution.value.events : [],
)
const adsDaySummary = computed(() => adsAttribution.value?.day_summary ?? null)
const adsUnattributedDays = computed(() =>
  Array.isArray(adsAttribution.value?.unattributed_days)
    ? adsAttribution.value.unattributed_days
    : [],
)
const adsHasUnattributed = computed(
  () =>
    Boolean(adsAttribution.value?.has_unattributed_spend) ||
    adsUnattributedDays.value.length > 0,
)
const adsNote = computed(() => {
  const fromDay = adsDaySummary.value?.note
  if (typeof fromDay === 'string' && fromDay) return fromDay
  const note = adsEvents.value.find((e) => e?.note)?.note
  return typeof note === 'string' ? note : null
})

function formatPctRatio(v) {
  if (v == null || !Number.isFinite(Number(v))) return '—'
  return `${(Number(v) * 100).toFixed(1)}%`
}

function formatRate(v) {
  if (v == null || !Number.isFinite(Number(v))) return '—'
  return Number(v).toFixed(4)
}

function adsAssistantHref(mlItemId) {
  if (!mlItemId || typeof window === 'undefined' || !window.route) return null
  try {
    return window.route('ads.assistant')
  } catch {
    return '/ads/assistant'
  }
}

const activeClaim = computed(() => {
  const rows = Array.isArray(claims.value) ? [...claims.value] : []
  if (!rows.length) return null
  rows.sort((a, b) => {
    const aOpen = a?.status === 'opened' ? 1 : 0
    const bOpen = b?.status === 'opened' ? 1 : 0
    if (aOpen !== bOpen) return bOpen - aOpen
    const aAt = a?.opened_at ? new Date(a.opened_at).getTime() : 0
    const bAt = b?.opened_at ? new Date(b.opened_at).getTime() : 0
    return bAt - aAt
  })
  return rows[0] ?? null
})

function openClaimsTab() {
  emit('update:selectedTab', 'claims')
}

const freshness = computed(() => {
  const meta = order.value?.meta ?? {}
  return meta.freshness_status ?? meta.freshness ?? null
})

const expectedProfit = computed(() => {
  const snapshots = order.value?.profit_snapshots ?? order.value?.profitSnapshots ?? []
  return snapshots.find((s) => s.stage === 'expected') ?? snapshots[0] ?? null
})

const cashWallet = ref(null)
function onCashWallet(payload) {
  cashWallet.value = payload
}

const postSaleCard = computed(() => {
  const summary = postSale.value
  if (!summary?.outcome || summary.outcome === 'claim_open') return null
  return summary
})

const stockBadgeVariant = computed(() => {
  const status =
    postSaleCard.value?.fulfillment?.inbound?.status ?? postSaleCard.value?.stock?.status
  if (status === 'restocked') return 'success'
  if (status === 'unmatched' || status === 'pending') return 'warning'
  return 'secondary'
})

const moneyBadgeVariant = computed(() => {
  const status =
    postSaleCard.value?.payment?.outbound?.status ?? postSaleCard.value?.money?.status
  if (status === 'refunded') return 'danger'
  if (status === 'partial_unknown') return 'warning'
  return 'secondary'
})

const paymentInbound = computed(() => postSaleCard.value?.payment?.inbound ?? null)
const paymentOutbound = computed(
  () => postSaleCard.value?.payment?.outbound ?? postSaleCard.value?.money ?? null,
)
const fulfillmentOutbound = computed(() => postSaleCard.value?.fulfillment?.outbound ?? null)
const fulfillmentInbound = computed(() => postSaleCard.value?.fulfillment?.inbound ?? null)

const channelLabel = computed(() => {
  const provider = order.value?.connection?.provider
  if (provider === 'mercadolibre') return 'Mercado Libre'
  return provider ?? '—'
})

const connectionLabel = computed(() => {
  const connection = order.value?.connection
  if (!connection) return null
  return connectionFilterLabel(connection)
})

const connectionColor = computed(() => order.value?.connection?.color ?? null)

const buyerMeta = computed(() => {
  const buyer = order.value?.meta?.buyer
  return buyer && typeof buyer === 'object' ? buyer : null
})

const buyerDisplay = computed(() => {
  const buyer = buyerMeta.value
  const id = order.value?.buyer_external_id
  const nickname = (buyer?.nickname?.trim?.() || buyer?.nickname || '').trim()
  const first = (buyer?.first_name?.trim?.() || buyer?.first_name || '').trim()
  const last = (buyer?.last_name?.trim?.() || buyer?.last_name || '').trim()
  const fullName = `${first} ${last}`.trim()

  if (fullName && nickname) {
    return {
      primary: fullName,
      secondary: nickname,
      primaryKind: 'name',
      secondaryKind: 'code',
    }
  }
  if (fullName) {
    return {
      primary: fullName,
      secondary: null,
      primaryKind: 'name',
      secondaryKind: null,
    }
  }
  if (nickname) {
    return {
      primary: nickname,
      secondary: null,
      primaryKind: 'code',
      secondaryKind: null,
    }
  }
  if (id != null && id !== '') {
    return {
      primary: `Comprador #${id}`,
      secondary: null,
      primaryKind: 'label',
      secondaryKind: null,
    }
  }
  return null
})

const canOpenBuyer = computed(() => {
  return Boolean(order.value?.buyer_external_id || buyerMeta.value)
})

const canSyncFromChannel = computed(() => {
  return order.value?.connection?.provider === 'mercadolibre'
})

function applyPayload(data) {
  order.value = data.order
  shipment.value = data.shipment
  timeline.value = data.timeline ?? []
  buyerInboundCount.value = Number(data.message_stats?.buyer_inbound_count ?? 0)
  claims.value = Array.isArray(data.claims) ? data.claims : []
  claimsOpenedCount.value = Number(data.claim_stats?.opened_count ?? 0)
  claimsTotalCount.value = Number(
    data.claim_stats?.total_count ?? claims.value.length,
  )
  postSale.value = data.post_sale ?? null
  adsAttribution.value = data.ads_attribution ?? null
  emitMeta()
}

function stockStatusLabel(status) {
  return (
    {
      restocked: 'Reingresado',
      unmatched: 'Sin match SKU',
      pending: 'Pendiente',
      not_applicable: 'No aplica',
    }[status] ?? status
  )
}

function emitMeta() {
  emit('meta', {
    hasShipment: Boolean(shipment.value?.id),
    shipmentId: shipment.value?.id ?? null,
    status: order.value?.status ?? null,
    externalOrderId: order.value?.external_order_id ?? order.value?.id ?? null,
    canSync: canSyncFromChannel.value,
    channelLabel: channelLabel.value,
    connectionId: order.value?.connection?.id ?? null,
    connectionLabel: connectionLabel.value,
    connectionColor: connectionColor.value,
    buyerInboundCount: buyerInboundCount.value,
    hasClaims: claimsTotalCount.value > 0,
    claimsOpenedCount: claimsOpenedCount.value,
    claimsTotalCount: claimsTotalCount.value,
    hasAds: Boolean(adsAttribution.value?.has_ads),
    adsTotalAmount: Number(adsAttribution.value?.total_amount ?? 0),
  })
}

function onMessageStats(stats) {
  buyerInboundCount.value = Number(stats?.buyerInboundCount ?? 0)
  emitMeta()
}

function getXsrfToken() {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  syncError.value = null
  try {
    const url = typeof window !== 'undefined' && window.route
      ? window.route('orders.show', id)
      : `/orders/${id}`
    const data = await fetchSlidePayload(url, { cache: 'no-store' })
    applyPayload(data)
  } catch (e) {
    const msg = typeof e?.message === 'string' ? e.message : 'No se pudo cargar la orden.'
    error.value = msg
    order.value = null
    shipment.value = null
    timeline.value = []
    buyerInboundCount.value = 0
    claims.value = []
    claimsOpenedCount.value = 0
    claimsTotalCount.value = 0
    postSale.value = null
    adsAttribution.value = null
    emit('meta', {
      hasShipment: false,
      shipmentId: null,
      externalOrderId: null,
      canSync: false,
      channelLabel: null,
      connectionId: null,
      connectionLabel: null,
      connectionColor: null,
      buyerInboundCount: 0,
      hasClaims: false,
      claimsOpenedCount: 0,
      claimsTotalCount: 0,
      hasAds: false,
      adsTotalAmount: 0,
    })
  } finally {
    loading.value = false
  }
}

async function syncNow() {
  if (!order.value?.id || syncing.value) return
  syncing.value = true
  syncError.value = null
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('orders.sync-now', order.value.id)
        : `/orders/${order.value.id}/sync-now`
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      credentials: 'same-origin',
      body: '{}',
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new Error(data.message || `Error ${response.status}`)
    }
    applyPayload(data)
  } catch (e) {
    syncError.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo actualizar la orden.'
  } finally {
    syncing.value = false
  }
}

function retry() {
  load(props.orderId)
}

function stepCaption(step) {
  if (!step?.done) return 'Pendiente'
  if (step.at) return formatRelativeShort(step.at)
  return 'Listo'
}

function stepTitle(step) {
  if (!step?.done) return step?.label ?? ''
  if (step.at) return `${step.label}: ${formatDateTime(step.at)}`
  return step.label
}

function onTimelineStepClick(step) {
  if (!step?.done) return
  if ((step.key === 'shipped' || step.key === 'delivered') && shipment.value?.id) {
    emit('open-shipment', shipment.value.id)
    return
  }
  emit('open-timeline-step', {
    key: step.key,
    orderId: order.value?.id ?? props.orderId,
    order: order.value,
  })
}

watch(
  () => props.orderId,
  (id) => {
    load(id)
  },
  { immediate: true },
)

defineExpose({ retry, loading, error, syncNow, syncing, canSyncFromChannel })
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <div v-if="loading" class="flex flex-1 items-center justify-center px-3 py-12 text-xs text-neutral-500 sm:px-4">
      Cargando orden…
    </div>
    <div v-else-if="error" class="flex flex-1 flex-col items-center justify-center gap-2 px-3 py-12 text-center sm:px-4">
      <p class="text-xs text-neutral-700">{{ error }}</p>
      <button type="button" class="text-xs font-medium text-brand hover:text-brand-hover" @click="retry">
        Reintentar
      </button>
    </div>
    <template v-else-if="order">
      <p
        v-if="syncError"
        class="shrink-0 border-b border-red-100 bg-red-50/90 px-3 py-1.5 text-xs text-red-600 sm:px-4"
      >
        {{ syncError }}
      </p>

      <div
        class="min-h-0 flex-1 px-3 py-3 sm:px-4"
        :class="
          selectedTab === 'messages' || selectedTab === 'claims' || selectedTab === 'invoice'
            ? 'flex flex-col overflow-hidden'
            : 'overflow-auto'
        "
      >
        <div v-show="selectedTab === 'details'" class="space-y-4">
        <div
          v-if="postSaleCard"
          class="rounded-xl border border-rose-200/80 bg-gradient-to-b from-rose-50/90 to-white px-3.5 py-3 shadow-sm"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wide text-rose-800/80">
                Inversión de la venta
              </p>
              <p class="mt-0.5 text-[13px] font-semibold text-rose-950">
                {{
                  postSaleCard.claim?.status_title
                    || (postSaleCard.outcome === 'returned'
                      ? 'Se invierte pago y envío'
                      : postSaleCard.outcome === 'refunded'
                        ? 'Se invierte el pago'
                        : 'Postventa')
                }}
              </p>
              <p
                v-if="postSaleCard.claim?.status_description"
                class="mt-0.5 text-[12px] text-rose-900/75"
              >
                {{ postSaleCard.claim.status_description }}
              </p>
            </div>
            <button
              type="button"
              class="text-[11px] font-semibold text-brand hover:underline"
              @click="openClaimsTab"
            >
              Ver reclamo →
            </button>
          </div>

          <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <!-- A. Pagos: ida → vuelta -->
            <div class="rounded-lg border border-rose-100 bg-white/80 px-3 py-2.5">
              <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                A · Pagos
              </p>
              <ol class="mt-2 space-y-2">
                <li class="rounded-md border border-emerald-100 bg-emerald-50/50 px-2.5 py-2">
                  <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-medium text-emerald-900">
                      1. {{ paymentInbound?.label || 'Pago recibido' }}
                    </span>
                    <Badge variant="success">Entrada</Badge>
                  </div>
                  <p
                    v-if="paymentInbound?.amount != null"
                    class="mt-0.5 text-[13px] font-semibold tabular-nums text-emerald-950"
                  >
                    <MoneyText
                      :amount="paymentInbound.amount"
                      :currency="paymentInbound.currency || order.currency_code"
                    />
                  </p>
                  <p
                    v-if="paymentInbound?.at"
                    class="mt-0.5 text-[10px] text-muted-foreground"
                  >
                    {{ formatDateTime(paymentInbound.at) }}
                  </p>
                </li>
                <li class="flex justify-center text-[10px] font-medium text-rose-700/80">
                  ↓ se invierte
                </li>
                <li class="rounded-md border border-rose-100 bg-rose-50/60 px-2.5 py-2">
                  <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-medium text-rose-950">
                      2. {{ paymentOutbound?.label || 'Salida al comprador' }}
                    </span>
                    <Badge v-if="paymentOutbound" :variant="moneyBadgeVariant">
                      {{
                        paymentOutbound.status === 'refunded' ? 'Salida' : 'Parcial'
                      }}
                    </Badge>
                  </div>
                  <p
                    v-if="paymentOutbound?.amount != null"
                    class="mt-0.5 text-[13px] font-semibold tabular-nums text-rose-900"
                  >
                    <MoneyText
                      :amount="paymentOutbound.amount"
                      :currency="paymentOutbound.currency || order.currency_code"
                    />
                  </p>
                  <p
                    v-else
                    class="mt-0.5 text-[11px] text-muted-foreground"
                  >
                    Sin salida registrada
                  </p>
                  <p
                    v-if="paymentOutbound?.at"
                    class="mt-0.5 text-[10px] text-muted-foreground"
                  >
                    {{ formatDateTime(paymentOutbound.at) }}
                  </p>
                </li>
              </ol>
            </div>

            <!-- B. Envío / producto: ida → vuelta -->
            <div class="rounded-lg border border-rose-100 bg-white/80 px-3 py-2.5">
              <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                B · Envío / producto
              </p>
              <ol class="mt-2 space-y-2">
                <li class="rounded-md border border-emerald-100 bg-emerald-50/50 px-2.5 py-2">
                  <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-medium text-emerald-900">
                      1. {{ fulfillmentOutbound?.label || 'Envío al cliente' }}
                    </span>
                    <Badge variant="success">Ida</Badge>
                  </div>
                  <p class="mt-0.5 text-[12px] capitalize text-emerald-950">
                    {{ fulfillmentOutbound?.status || shipment?.status || '—' }}
                  </p>
                  <p
                    v-if="fulfillmentOutbound?.delivered_at || shipment?.delivered_at"
                    class="mt-0.5 text-[10px] text-muted-foreground"
                  >
                    Entregado
                    {{
                      formatDateTime(
                        fulfillmentOutbound?.delivered_at || shipment?.delivered_at,
                      )
                    }}
                  </p>
                  <p
                    v-else-if="fulfillmentOutbound?.shipped_at || shipment?.shipped_at"
                    class="mt-0.5 text-[10px] text-muted-foreground"
                  >
                    Enviado
                    {{
                      formatDateTime(fulfillmentOutbound?.shipped_at || shipment?.shipped_at)
                    }}
                  </p>
                </li>
                <li class="flex justify-center text-[10px] font-medium text-rose-700/80">
                  ↓ se invierte
                </li>
                <li class="rounded-md border border-rose-100 bg-rose-50/60 px-2.5 py-2">
                  <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-medium text-rose-950">
                      2. {{ fulfillmentInbound?.label || 'Devolución del producto' }}
                    </span>
                    <Badge :variant="stockBadgeVariant">
                      {{
                        stockStatusLabel(
                          fulfillmentInbound?.status || postSaleCard.stock?.status,
                        )
                      }}
                    </Badge>
                  </div>
                  <p class="mt-0.5 text-[12px] text-rose-900/90">
                    {{
                      fulfillmentInbound?.status_label
                        || postSaleCard.stock?.label
                        || '—'
                    }}
                  </p>
                  <p
                    v-if="fulfillmentInbound?.at"
                    class="mt-0.5 text-[10px] text-muted-foreground"
                  >
                    {{ formatDateTime(fulfillmentInbound.at) }}
                  </p>
                  <ul
                    v-if="(postSaleCard.stock?.lines || []).length"
                    class="mt-1 space-y-0.5 text-[10px] text-muted-foreground"
                  >
                    <li
                      v-for="line in postSaleCard.stock.lines"
                      :key="line.order_line_id"
                    >
                      {{ line.sku || 'Sin SKU' }}
                      · {{ stockStatusLabel(line.status) }}
                    </li>
                  </ul>
                </li>
              </ol>
            </div>
          </div>
        </div>

        <!-- Resumen en 3 columnas -->
        <section class="overflow-hidden rounded-xl border border-slate-200/80 bg-white">
          <div class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            <div class="px-3.5 py-3">
              <div class="flex items-start justify-between gap-2">
                <p class="text-[10px] font-medium uppercase tracking-wide text-neutral-500">
                  Total
                </p>
                <FreshnessBadge v-if="freshness" :status="freshness" />
              </div>
              <p class="mt-1 text-[18px] font-semibold tracking-tight tabular-nums text-slate-900">
                <MoneyText :amount="order.total_amount" :currency="order.currency_code" />
              </p>
              <p
                v-if="order.ordered_at"
                class="mt-1 text-[11px] text-neutral-500"
                :title="formatDateTime(order.ordered_at)"
              >
                {{ formatRelativeShort(order.ordered_at) }}
              </p>
            </div>

            <div class="min-w-0 px-3.5 py-3">
              <p class="text-[10px] font-medium uppercase tracking-wide text-neutral-500">
                Comprador
              </p>
              <button
                v-if="canOpenBuyer && buyerDisplay"
                type="button"
                class="mt-1 w-full min-w-0 text-left hover:opacity-80"
                @click="emit('open-buyer', order)"
              >
                <StackedData
                  v-bind="buyerDisplay"
                  primary-class="text-brand"
                />
              </button>
              <div v-else-if="buyerDisplay" class="mt-1 min-w-0">
                <StackedData v-bind="buyerDisplay" />
              </div>
              <span v-else class="mt-1 block text-[12px] text-muted-foreground">—</span>
            </div>

            <div class="min-w-0 px-3.5 py-3">
              <p class="text-[10px] font-medium uppercase tracking-wide text-neutral-500">
                Envío
              </p>
              <template v-if="shipment">
                <p class="mt-1 text-[13px] font-semibold capitalize tracking-tight text-slate-900">
                  {{ String(shipment.status || '').replaceAll('_', ' ') || '—' }}
                </p>
                <p
                  v-if="shipment.tracking_number"
                  class="mt-0.5 truncate font-mono text-[10px] text-neutral-500"
                  :title="shipment.tracking_number"
                >
                  {{ shipment.tracking_number }}
                </p>
                <button
                  v-if="shipment.id"
                  type="button"
                  class="mt-1.5 text-[11px] font-semibold text-brand hover:underline"
                  @click="emit('open-shipment', shipment.id)"
                >
                  Ver envío →
                </button>
              </template>
              <p v-else class="mt-1 text-[13px] text-muted-foreground">Sin envío</p>
            </div>
          </div>
        </section>

        <!-- Progreso: una sola fila compacta -->
        <section>
          <h3 class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
            Progreso
          </h3>
          <ol class="flex w-full items-stretch rounded-xl border border-slate-200/80 bg-slate-50/40 px-1 py-2 sm:px-2">
            <li
              v-for="(step, index) in timeline"
              :key="step.key"
              class="relative flex min-w-0 flex-1 flex-col items-center px-0.5"
            >
              <div
                v-if="index < timeline.length - 1"
                class="absolute left-1/2 top-[22px] h-px w-full"
                :class="step.done && timeline[index + 1]?.done ? 'bg-emerald-400' : 'bg-slate-200'"
                aria-hidden="true"
              />
              <button
                type="button"
                class="relative z-[1] flex w-full flex-col items-center rounded-md px-0.5 py-0.5 text-center transition"
                :class="step.done ? 'hover:bg-white/80' : 'cursor-default'"
                :disabled="!step.done"
                :title="stepTitle(step)"
                @click="onTimelineStepClick(step)"
              >
                <span
                  class="max-w-full truncate text-[9px] font-medium uppercase tracking-wide"
                  :class="step.done ? 'text-emerald-800' : 'text-slate-400'"
                >
                  {{ step.label }}
                </span>
                <span
                  class="mt-1.5 size-2.5 shrink-0 rounded-full ring-2 ring-slate-50"
                  :class="step.done ? 'bg-emerald-500' : 'bg-slate-300'"
                  aria-hidden="true"
                />
                <span
                  class="mt-1.5 max-w-full truncate text-[10px] tabular-nums"
                  :class="step.done ? 'font-medium text-slate-700' : 'text-slate-400'"
                >
                  {{ stepCaption(step) }}
                </span>
                <span
                  v-if="step.done"
                  class="mt-0.5 text-[9px] font-semibold text-brand"
                >
                  1 registro · Ver →
                </span>
              </button>
            </li>
          </ol>
        </section>

        <ProfitBreakdown
          v-if="expectedProfit"
          :snapshot="expectedProfit"
          :post-sale-outcome="order.post_sale_outcome"
          :mp-balance="cashWallet?.total"
          :mp-balance-status="cashWallet?.status"
          :mp-balance-reserved="cashWallet?.reserved"
          :has-buyer-shipping="cashWallet?.has_shipping_credit"
        />

        <OrderCashReconciliation
          v-if="order?.id"
          :order-id="order.id"
          @wallet="onCashWallet"
        />

        <div>
          <h3 class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
            Líneas
          </h3>
          <div class="overflow-hidden rounded-xl border border-slate-200/70">
            <table class="min-w-full text-left text-[12px]">
              <thead class="bg-slate-50/80 text-[10px] uppercase tracking-wide text-muted-foreground">
                <tr>
                  <th class="px-2.5 py-1.5 font-medium">SKU</th>
                  <th class="px-2.5 py-1.5 font-medium">Título</th>
                  <th class="px-2.5 py-1.5 font-medium">Qty</th>
                  <th class="px-2.5 py-1.5 font-medium">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="line in order.lines ?? []"
                  :key="line.id"
                  class="border-t border-slate-100"
                >
                  <td class="px-2.5 py-1.5 font-mono text-[10px] tracking-tight">
                    {{ line.sku ? toCodeCase(line.sku) : '—' }}
                  </td>
                  <td class="px-2.5 py-1.5">
                    <button
                      v-if="lineProductPayload(line)"
                      type="button"
                      class="text-left font-medium text-brand hover:underline"
                      @click="openLineProduct(line)"
                    >
                      {{ lineTitle(line) }}
                    </button>
                    <span v-else>{{ lineTitle(line) }}</span>
                  </td>
                  <td class="px-2.5 py-1.5 tabular-nums">{{ line.quantity }}</td>
                  <td class="px-2.5 py-1.5 tabular-nums">
                    <MoneyText :amount="line.line_total_amount" :currency="line.currency_code" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div v-show="selectedTab === 'shipment'" class="space-y-3">
        <template v-if="shipment || fulfillmentOutbound">
          <div
            v-if="postSaleCard && (fulfillmentOutbound || fulfillmentInbound)"
            class="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50 to-white px-3.5 py-3"
          >
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
              Flujo logístico
            </p>
            <p class="mt-0.5 text-[12px] text-slate-600">
              Primero la entrega al cliente; si hay devolución, la inversión.
            </p>
            <ol class="mt-3 space-y-2">
              <li class="rounded-lg border border-emerald-100 bg-emerald-50/60 px-3 py-2.5">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[12px] font-semibold text-emerald-950">
                    1. {{ fulfillmentOutbound?.label || 'Envío al cliente' }}
                  </span>
                  <Badge variant="success">Ida</Badge>
                </div>
                <dl class="mt-2 grid gap-1.5 sm:grid-cols-2 text-[11px]">
                  <div>
                    <dt class="text-muted-foreground">Estado</dt>
                    <dd class="capitalize font-medium text-slate-900">
                      {{ fulfillmentOutbound?.status || shipment?.status || '—' }}
                    </dd>
                  </div>
                  <div>
                    <dt class="text-muted-foreground">Tracking</dt>
                    <dd class="font-mono break-all">
                      {{
                        fulfillmentOutbound?.tracking_number
                          || shipment?.tracking_number
                          || '—'
                      }}
                    </dd>
                  </div>
                  <div>
                    <dt class="text-muted-foreground">Enviado</dt>
                    <dd>
                      {{
                        (fulfillmentOutbound?.shipped_at || shipment?.shipped_at)
                          ? formatDateTime(
                              fulfillmentOutbound?.shipped_at || shipment?.shipped_at,
                            )
                          : '—'
                      }}
                    </dd>
                  </div>
                  <div>
                    <dt class="text-muted-foreground">Entregado</dt>
                    <dd>
                      {{
                        (fulfillmentOutbound?.delivered_at || shipment?.delivered_at)
                          ? formatDateTime(
                              fulfillmentOutbound?.delivered_at || shipment?.delivered_at,
                            )
                          : '—'
                      }}
                    </dd>
                  </div>
                </dl>
              </li>
              <li
                v-if="fulfillmentInbound"
                class="flex justify-center text-[10px] font-medium text-rose-700/80"
              >
                ↓ se invierte
              </li>
              <li
                v-if="fulfillmentInbound"
                class="rounded-lg border border-rose-100 bg-rose-50/70 px-3 py-2.5"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[12px] font-semibold text-rose-950">
                    2. {{ fulfillmentInbound.label }}
                  </span>
                  <Badge :variant="stockBadgeVariant">
                    {{ stockStatusLabel(fulfillmentInbound.status) }}
                  </Badge>
                </div>
                <p class="mt-1 text-[12px] text-rose-900/90">
                  {{ fulfillmentInbound.status_label }}
                </p>
                <p
                  v-if="fulfillmentInbound.at"
                  class="mt-0.5 text-[10px] text-muted-foreground"
                >
                  {{ formatDateTime(fulfillmentInbound.at) }}
                </p>
              </li>
            </ol>
          </div>

          <dl v-if="shipment" class="grid gap-2.5 sm:grid-cols-2">
            <div>
              <dt class="text-[10px] text-muted-foreground">Mensajería</dt>
              <dd class="mt-0.5 text-[12px]">
                {{ shipment.carrier || shipment.meta?.tracking_method || '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-[10px] text-muted-foreground">Modo</dt>
              <dd class="mt-0.5 text-[12px] capitalize">
                {{ shipment.meta?.logistic_type || shipment.meta?.mode || '—' }}
              </dd>
            </div>
            <div v-if="shipment.meta?.substatus">
              <dt class="text-[10px] text-muted-foreground">Subestado</dt>
              <dd class="mt-0.5 text-[12px] capitalize">{{ shipment.meta.substatus }}</dd>
            </div>
          </dl>
          <Button
            v-if="shipment?.id"
            type="button"
            size="sm"
            class="h-7 px-2.5 text-[11px]"
            @click="emit('open-shipment', shipment.id)"
          >
            Abrir panel de envío
          </Button>
        </template>
        <p v-else class="text-xs text-muted-foreground">Esta orden aún no tiene envío.</p>
      </div>

      <div v-show="selectedTab === 'messages'" class="flex min-h-0 flex-1 flex-col">
        <OrderMessagesPanel
          :order-id="orderId"
          :active="messagesTabActive"
          :active-claim="activeClaim"
          @stats="onMessageStats"
          @open-claims="openClaimsTab"
        />
      </div>

      <div v-show="selectedTab === 'claims'" class="flex min-h-0 flex-1 flex-col">
        <OrderClaimsPanel
          :order-id="orderId"
          :claims="claims"
          :active="claimsTabActive"
          :loading-claims="loading"
        />
      </div>

      <div v-show="selectedTab === 'invoice'" class="flex min-h-0 flex-1 flex-col">
        <OrderInvoicePanel
          :order-id="orderId"
          :active="invoiceTabActive"
          :currency="order.currency_code || 'MXN'"
        />
      </div>

      <div v-show="selectedTab === 'ads'" class="space-y-4">
        <div
          v-if="!adsAttribution?.has_ads && !adsHasUnattributed"
          class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500"
        >
          Esta orden no tiene gasto de publicidad atribuido.
        </div>

        <template v-if="!adsAttribution?.has_ads && adsHasUnattributed">
          <div class="rounded-lg border border-amber-200 bg-amber-50/70 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">
              Sin atribución ML
            </p>
            <p class="mt-1 text-sm text-amber-950">
              Hubo gasto de Product Ads el día de esta venta, pero Mercado Ads no atribuyó
              revenue (posible búsqueda orgánica / directa). No cargamos ese gasto al P&amp;L
              de la orden.
            </p>
          </div>
          <div
            v-for="(day, idx) in adsUnattributedDays"
            :key="`${day.ml_item_id}-${day.date}-${idx}`"
            class="overflow-hidden rounded-lg border border-slate-200"
          >
            <div class="border-b border-slate-100 bg-slate-50 px-3 py-2">
              <p class="font-mono text-xs text-slate-800">{{ day.ml_item_id }}</p>
              <a
                v-if="day.ml_item_id && adsAssistantHref(day.ml_item_id)"
                :href="adsAssistantHref(day.ml_item_id)"
                class="mt-0.5 inline-block text-[11px] font-medium text-teal-700 hover:underline"
              >
                Ver en Asistente Ads
              </a>
            </div>
            <div class="grid gap-2 p-3 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <p class="text-[10px] uppercase tracking-wide text-slate-500">Gasto día</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">
                  <MoneyText :amount="day.ads_cost" :currency="adsCurrency" />
                </p>
              </div>
              <div>
                <p class="text-[10px] uppercase tracking-wide text-slate-500">Revenue ML</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">
                  <MoneyText :amount="0" :currency="adsCurrency" />
                </p>
              </div>
              <div>
                <p class="text-[10px] uppercase tracking-wide text-slate-500">Residual / waste</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">
                  <MoneyText :amount="day.residual" :currency="adsCurrency" />
                </p>
              </div>
              <div>
                <p class="text-[10px] uppercase tracking-wide text-slate-500">Orgánico ML</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">
                  <MoneyText :amount="day.organic_amount" :currency="adsCurrency" />
                  <span class="text-[11px] font-normal text-slate-500">
                    · {{ day.organic_units ?? 0 }} uds
                  </span>
                </p>
              </div>
            </div>
            <p v-if="day.note" class="border-t border-slate-100 px-3 py-2 text-xs text-slate-600">
              {{ day.note }}
            </p>
          </div>
        </template>

        <template v-if="adsAttribution?.has_ads">
          <div class="rounded-lg border border-slate-200 bg-white p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
              Ads asignados a esta orden
            </p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-slate-900">
              <MoneyText
                :amount="adsAttribution.total_amount"
                :currency="adsCurrency"
              />
            </p>
            <p class="mt-1 text-xs text-slate-500">
              Costo de Product Ads repartido a esta orden (solo ventas atribuidas por ML).
            </p>
            <p v-if="adsNote" class="mt-2 text-xs text-slate-600">{{ adsNote }}</p>
          </div>

          <div class="overflow-hidden rounded-lg border border-slate-200">
            <table class="min-w-full text-xs">
              <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                  <th class="px-3 py-2 font-medium">Ítem ML</th>
                  <th class="px-3 py-2 font-medium">Fecha spend</th>
                  <th class="px-3 py-2 text-right font-medium">Rate</th>
                  <th class="px-3 py-2 text-right font-medium">Costo día</th>
                  <th class="px-3 py-2 text-right font-medium">Coverage</th>
                  <th class="px-3 py-2 text-right font-medium">Asignado</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(row, idx) in adsEvents"
                  :key="`${row.order_line_id || 'x'}-${row.ml_item_id || idx}-${row.date || idx}`"
                  class="border-t border-slate-100 align-top"
                >
                  <td class="px-3 py-2">
                    <p class="font-mono text-slate-800">{{ row.ml_item_id || '—' }}</p>
                    <a
                      v-if="row.ml_item_id && adsAssistantHref(row.ml_item_id)"
                      :href="adsAssistantHref(row.ml_item_id)"
                      class="mt-0.5 inline-block text-[11px] font-medium text-teal-700 hover:underline"
                    >
                      Ver en Asistente Ads
                    </a>
                    <p v-if="row.model" class="mt-0.5 text-[10px] text-slate-400">
                      {{ row.model }}
                    </p>
                  </td>
                  <td class="px-3 py-2 tabular-nums text-slate-700">
                    {{ row.date || '—' }}
                  </td>
                  <td class="px-3 py-2 text-right tabular-nums text-slate-700">
                    {{ formatRate(row.rate) }}
                  </td>
                  <td class="px-3 py-2 text-right">
                    <MoneyText :amount="row.ads_cost" :currency="adsCurrency" />
                  </td>
                  <td class="px-3 py-2 text-right tabular-nums text-slate-700">
                    {{ formatPctRatio(row.coverage_ratio) }}
                  </td>
                  <td class="px-3 py-2 text-right font-medium">
                    <MoneyText :amount="row.amount" :currency="adsCurrency" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
              <p class="text-[10px] uppercase tracking-wide text-slate-500">Gasto día (ítem)</p>
              <p class="mt-0.5 text-sm font-semibold tabular-nums">
                <MoneyText
                  :amount="adsDaySummary?.ads_cost"
                  :currency="adsCurrency"
                />
              </p>
            </div>
            <div class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
              <p class="text-[10px] uppercase tracking-wide text-slate-500">Revenue ML (día)</p>
              <p class="mt-0.5 text-sm font-semibold tabular-nums">
                <MoneyText
                  :amount="adsDaySummary?.ml_revenue"
                  :currency="adsCurrency"
                />
              </p>
            </div>
            <div class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
              <p class="text-[10px] uppercase tracking-wide text-slate-500">GMV local (día)</p>
              <p class="mt-0.5 text-sm font-semibold tabular-nums">
                <MoneyText
                  :amount="adsDaySummary?.item_gmv"
                  :currency="adsCurrency"
                />
              </p>
            </div>
            <div class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
              <p class="text-[10px] uppercase tracking-wide text-slate-500">Residual día</p>
              <p class="mt-0.5 text-sm font-semibold tabular-nums">
                <MoneyText
                  :amount="adsDaySummary?.residual ?? 0"
                  :currency="adsCurrency"
                />
              </p>
            </div>
          </div>
        </template>
      </div>
      </div>
    </template>
  </div>
</template>
