<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { AlertTriangle, FileSpreadsheet, RefreshCw } from 'lucide-vue-next'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/App/PageHeader.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import DataTable from '@/Components/App/DataTable.vue'
import FilterBar from '@/Components/App/FilterBar.vue'
import InfiniteListSummary from '@/Components/App/InfiniteListSummary.vue'
import StackedData from '@/Components/App/StackedData.vue'
import Card from '@/Components/ui/Card.vue'
import Button from '@/Components/ui/Button.vue'
import Badge from '@/Components/ui/Badge.vue'
import Input from '@/Components/ui/Input.vue'
import TableHead from '@/Components/ui/TableHead.vue'
import TableRow from '@/Components/ui/TableRow.vue'
import TableCell from '@/Components/ui/TableCell.vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import MarketplacePaymentDetail from '@/Components/Domain/MarketplacePaymentDetail.vue'
import OverdueReleaseCoverageBar from '@/Components/Domain/OverdueReleaseCoverageBar.vue'
import { useInfiniteList } from '@/composables/useInfiniteList'
import { connectionFilterLabel } from '@/lib/connectionLabel'
import { connectionSurfaceStyle, resolveConnectionColor } from '@/lib/connectionColor'
import { jsonFetch } from '@/lib/jsonFetch'
import { ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS } from '@/lib/slideOverLayout'
import { formatDateTime, formatRelativeShort } from '@/lib/utils'
import { cashConceptLabel, ledgerFilterLabel, matchMethodLabel } from '@/lib/cashMovementConcept'

const OrderDetailSlideOver = defineAsyncComponent(
  () => import('@/Components/Orders/OrderDetailSlideOver.vue'),
)

const props = defineProps({
  view: { type: String, default: 'payments' },
  rows: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
  connections: { type: Array, default: () => [] },
  alert_count: { type: Number, default: 0 },
  overdue_count: { type: Number, default: 0 },
  overdue_kpis: { type: Object, default: null },
  release_coverage: { type: Object, default: null },
  payments_total: { type: Number, default: 0 },
  ledger_total: { type: Number, default: 0 },
  withdrawals_total: { type: Number, default: 0 },
})

const localFilters = ref({
  view: props.filters?.view ?? props.view ?? 'payments',
  q: props.filters?.q ?? '',
  entry_type: props.filters?.entry_type ?? '',
  status: props.filters?.status ?? '',
  kind: props.filters?.kind ?? '',
  connection_id:
    props.filters?.connection_id != null && props.filters?.connection_id !== ''
      ? String(props.filters.connection_id)
      : '',
})

watch(
  () => props.filters,
  (f) => {
    localFilters.value = {
      view: f?.view ?? props.view ?? 'payments',
      q: f?.q ?? '',
      entry_type: f?.entry_type ?? '',
      status: f?.status ?? '',
      kind: f?.kind ?? '',
      connection_id:
        f?.connection_id != null && f?.connection_id !== ''
          ? String(f.connection_id)
          : '',
    }
  },
)

const view = computed(() => localFilters.value.view || 'payments')
const isPayments = computed(() => view.value === 'payments')
const isLedger = computed(() => view.value === 'ledger')
const isReleases = computed(() => view.value === 'releases')
const isWithdrawals = computed(() => view.value === 'withdrawals')
const isOverdue = computed(() => view.value === 'overdue')

const tabs = computed(() => {
  const filteredTotal = Number(props.rows?.total)
  const paymentsCount = isPayments.value && Number.isFinite(filteredTotal)
    ? filteredTotal
    : props.payments_total
  const withdrawalsCount = isWithdrawals.value && Number.isFinite(filteredTotal)
    ? filteredTotal
    : props.withdrawals_total
  const ledgerCount = isLedger.value && Number.isFinite(filteredTotal)
    ? filteredTotal
    : props.ledger_total
  const overdueCount = isOverdue.value && Number.isFinite(filteredTotal)
    ? filteredTotal
    : props.overdue_count

  return [
    { key: 'payments', label: 'Pagos', count: paymentsCount },
    { key: 'overdue', label: 'Atrasadas', count: overdueCount },
    { key: 'releases', label: 'Liberaciones', count: isReleases.value && Number.isFinite(filteredTotal) ? filteredTotal : null },
    { key: 'withdrawals', label: 'Retiros', count: withdrawalsCount },
    { key: 'ledger', label: 'Ledger MP', count: ledgerCount },
  ]
})

const {
  displayItems,
  isLoadingMore,
  hasMorePages,
  loadMoreSentinel,
  resetAccumulation,
  totalCount,
  paginationFrom,
  paginationTo,
} = useInfiniteList({
  initialPaginator: computed(() => props.rows),
})

watch(
  () => props.rows,
  () => resetAccumulation(),
)

function applyFilters() {
  resetAccumulation()
  router.get(
    route('finance.cash.index'),
    {
      ...localFilters.value,
      page: 1,
    },
    { preserveState: true, replace: true },
  )
}

function setView(next) {
  localFilters.value.view = next
  if (next !== 'payments') localFilters.value.status = ''
  if (next !== 'ledger') localFilters.value.entry_type = ''
  if (next !== 'overdue') localFilters.value.kind = ''
  if (next === 'releases') localFilters.value.q = ''
  applyFilters()
}

function filterAlerts() {
  localFilters.value.view = 'payments'
  localFilters.value.status = 'short'
  localFilters.value.q = ''
  applyFilters()
}

let searchTimer = null
watch(
  () => localFilters.value.q,
  () => {
    if (isReleases.value) return
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => applyFilters(), 300)
  },
)

const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref(null)
const detail = ref(null)
const syncMessage = ref(null)
const syncProgress = ref(null)
const syncStats = ref(null)
const syncPolling = ref(false)
const syncPanelOpen = ref(false)
const reportFiles = ref([])
const csvPreview = ref(null)
const csvPreviewLoading = ref(false)
const csvPreviewError = ref(null)
let syncPollTimer = null

const syncConnectionId = computed(() => {
  const selected = localFilters.value.connection_id
  if (selected) return Number(selected)
  return props.connections?.[0]?.id ? Number(props.connections[0].id) : null
})

const selectedConnection = computed(() =>
  props.connections.find((c) => String(c.id) === String(localFilters.value.connection_id)) || null,
)

const reportProgressRows = computed(() => {
  const reports = syncProgress.value?.reports || {}
  const order = ['settlement', 'release']
  const labels = { settlement: 'Settlement (cobros)', release: 'Liberaciones (oficial)' }
  return order
    .filter((k) => reports[k])
    .map((k) => ({ key: k, label: labels[k] || k, ...reports[k] }))
})

const showSyncPanel = computed(
  () =>
    syncPanelOpen.value
    || syncPolling.value
    || Boolean(syncMessage.value)
    || Boolean(syncProgress.value?.started_at)
    || reportFiles.value.length > 0,
)

const viewHint = computed(() => {
  if (isOverdue.value) {
    return 'Entregadas hace más de 2 días cuyo dinero aún no se liberó. Huérfana = sin cobro; sin liberar = cobro sin release; en reserva = mediación o reclamo.'
  }
  if (isReleases.value) {
    return 'Monto liberado por hora (como MP). Preferimos el reporte oficial; si no hay, usamos settlements. Click para el detalle del lote.'
  }
  if (isWithdrawals.value) {
    return 'Retiros reales al banco (PAYOUTS). Click para ver órdenes atribuidas (FIFO).'
  }
  if (isLedger.value) {
    return 'Vista técnica fila a fila. El total por hora está en Liberaciones.'
  }
  return null
})

const emptyTitle = computed(() => {
  if (isPayments.value) return 'Sin pagos'
  if (isOverdue.value) return 'Sin liberaciones atrasadas'
  if (isReleases.value) return 'Sin liberaciones'
  if (isWithdrawals.value) return 'Sin retiros'
  return 'Sin movimientos'
})

const emptyDescription = computed(() => {
  if (isPayments.value) return 'Los pagos aparecen al sincronizar cobros del canal.'
  if (isOverdue.value) return 'Cuando una entrega ya debió liberarse y no aparece, sale aquí.'
  if (isReleases.value) return 'Corre Sync reportes para traer liberaciones de Mercado Pago.'
  if (isWithdrawals.value) return 'Los retiros llegan con el settlement report (PAYOUTS).'
  return 'Sincroniza reportes MP para llenar el ledger.'
})

function overdueKindLabel(kind) {
  if (kind === 'orphan') return 'Huérfana'
  if (kind === 'unreleased') return 'Sin liberar'
  if (kind === 'held') return 'En reserva'
  if (kind === 'overdue') return 'Atrasada'
  return kind || '—'
}

function overdueKindVariant(kind) {
  if (kind === 'orphan' || kind === 'unreleased') return 'danger'
  if (kind === 'held') return 'warning'
  return 'outline'
}

function expectedReleaseSourceLabel(source) {
  if (source === 'ml') return 'ML'
  if (source === 'estimated') return 'entrega + 2d'
  return ''
}

function onReleaseCoverageSynced() {
  if (isOverdue.value) applyFilters()
}

function kindLabel(kind) {
  if (kind === 'release') return 'Liberaciones'
  if (kind === 'settlement') return 'Settlement'
  return kind
}

function formatBytes(n) {
  const bytes = Number(n) || 0
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function phaseTone(phase) {
  if (phase === 'done') return 'text-emerald-700'
  if (phase === 'failed') return 'text-rose-700'
  if (phase === 'skipped') return 'text-amber-700'
  if (['waiting_report', 'downloading', 'parsing', 'ingesting', 'running', 'configuring', 'requesting', 'queued'].includes(phase)) {
    return 'text-sky-700'
  }
  return 'text-slate-700'
}

function phaseLabel(phase) {
  const map = {
    queued: 'En cola',
    running: 'Iniciando',
    configuring: 'Config',
    requesting: 'Solicitando',
    ready: 'Listo para bajar',
    waiting_report: 'Esperando MP',
    downloading: 'Descargando',
    parsing: 'Parseando',
    ingesting: 'Ingestando',
    done: 'Listo',
    failed: 'Error',
    skipped: 'Omitido',
  }
  return map[phase] || phase || '—'
}

function reconciliationLabel(status) {
  const map = {
    balanced: 'Cuadrado',
    short: 'Faltante',
    over: 'Sobrante',
    pending: 'Pendiente',
    incomplete: 'Incompleto',
    reserved: 'Retenido',
    in_mediation: 'En mediación',
  }
  return map[status] || status || '—'
}

function reconciliationVariant(status) {
  if (status === 'balanced') return 'success'
  if (status === 'short') return 'danger'
  if (status === 'over') return 'warning'
  if (status === 'reserved' || status === 'in_mediation') return 'warning'
  return 'outline'
}

function paymentRowStatus(row) {
  if (row?.status === 'in_mediation' || row?.in_mediation) return 'in_mediation'
  return row?.reconciliation_status
}

function connectionForRow(row) {
  if (row?.connection) return row.connection
  if (row?.connection_id) {
    return props.connections.find((c) => Number(c.id) === Number(row.connection_id)) || null
  }
  return null
}

function getXsrfToken() {
  if (typeof document === 'undefined') return ''
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

function stopSyncPolling() {
  if (syncPollTimer) {
    clearInterval(syncPollTimer)
    syncPollTimer = null
  }
  syncPolling.value = false
}

async function refreshSyncStatus(connectionId) {
  if (!connectionId) return
  try {
    const data = await jsonFetch(route('finance.cash.sync-status', { connection_id: connectionId }))
    syncProgress.value = data.progress || null
    syncStats.value = data.stats || null
    if (Array.isArray(data.report_files)) {
      reportFiles.value = data.report_files
    }
    if (data.progress?.active) {
      syncMessage.value = 'Sync en progreso…'
      syncPanelOpen.value = true
    } else if (data.progress?.updated_at) {
      const reports = data.progress.reports || {}
      const failed = Object.values(reports).some((r) => r?.phase === 'failed')
      syncMessage.value = failed
        ? 'Sync terminó con errores. Revisa el detalle abajo.'
        : 'Sync terminado. Revisa Liberaciones / Ledger.'
      stopSyncPolling()
      if (isReleases.value || isLedger.value || isOverdue.value) {
        applyFilters()
      }
    }
  } catch {
    // keep last known progress
  }
}

async function loadReportFiles(connectionId) {
  if (!connectionId) {
    reportFiles.value = []
    return
  }
  try {
    const data = await jsonFetch(route('finance.cash.report-files', { connection_id: connectionId }))
    reportFiles.value = data.files || []
  } catch {
    reportFiles.value = []
  }
}

function startSyncPolling(connectionId) {
  stopSyncPolling()
  syncPolling.value = true
  syncPanelOpen.value = true
  refreshSyncStatus(connectionId)
  syncPollTimer = setInterval(() => refreshSyncStatus(connectionId), 3000)
}

async function openCsvPreview(fileId) {
  csvPreviewLoading.value = true
  csvPreviewError.value = null
  csvPreview.value = null
  try {
    csvPreview.value = await jsonFetch(route('finance.cash.report-files.preview', fileId))
  } catch (e) {
    csvPreviewError.value = e?.message ?? 'No se pudo previsualizar el CSV'
  } finally {
    csvPreviewLoading.value = false
  }
}

function closeCsvPreview() {
  csvPreview.value = null
  csvPreviewError.value = null
}

function fileForProgressRow(row) {
  if (row.local_file_id) {
    return reportFiles.value.find((f) => f.id === row.local_file_id) || null
  }
  if (!row.file_name) return null
  return reportFiles.value.find((f) => f.remote_file_name === row.file_name && f.report_kind === row.key) || null
}

async function openRow(row) {
  if (isOverdue.value) {
    openOrderFromBucket({
      order_id: row.order_id || row.id,
      external_order_id: row.external_order_id,
    })
    return
  }
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = null
  fetchOrderError.value = null
  detail.value = null
  try {
    if (isPayments.value) {
      detail.value = await jsonFetch(route('finance.cash.payments.show', row.id))
    } else if (isReleases.value) {
      detail.value = await jsonFetch(route('finance.cash.releases.show', { id: row.id }))
    } else {
      detail.value = await jsonFetch(route('finance.cash.entries.show', row.id))
    }
  } catch (e) {
    detailError.value = e?.message ?? 'Error al cargar detalle'
  } finally {
    detailLoading.value = false
  }
}

async function retryOverdueSync(row) {
  const externalId = row?.external_order_id
  const connectionId = row?.connection_id || localFilters.value.connection_id || syncConnectionId.value
  if (!externalId || !connectionId) return
  if (fetchingOrderKey.value) return

  fetchingOrderKey.value = String(externalId)
  fetchOrderError.value = null
  try {
    await jsonFetch(route('finance.cash.fetch-order'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      body: JSON.stringify({
        connection_id: Number(connectionId),
        external_order_id: String(externalId),
      }),
    })
    applyFilters()
  } catch (e) {
    fetchOrderError.value = e?.message ?? 'No se pudo reintentar el sync'
  } finally {
    fetchingOrderKey.value = null
  }
}

async function openPaymentFromBucket(paymentId) {
  if (!paymentId) return
  detailLoading.value = true
  detailError.value = null
  try {
    detail.value = await jsonFetch(route('finance.cash.payments.show', paymentId))
  } catch (e) {
    detailError.value = e?.message ?? 'Error al cargar pago'
  } finally {
    detailLoading.value = false
  }
}

const orderSlideOpen = ref(false)
const selectedOrderId = ref(null)
const selectedOrderExternalId = ref(null)

function openOrderFromBucket(row) {
  const orderId = row?.order_id
  if (!orderId) return
  selectedOrderId.value = Number(orderId)
  selectedOrderExternalId.value = row?.external_order_id ?? null
  orderSlideOpen.value = true
}

function closeOrderSlide() {
  orderSlideOpen.value = false
  selectedOrderId.value = null
  selectedOrderExternalId.value = null
}

const fetchingOrderKey = ref(null)
const fetchingAllMissing = ref(false)
const fetchOrderError = ref(null)
const fetchAllProgress = ref(null)

const bucketGroups = computed(() => {
  if (detail.value?.kind !== 'release_bucket') return []
  if (Array.isArray(detail.value.groups) && detail.value.groups.length) {
    return detail.value.groups
  }
  return (detail.value.payments ?? []).map((p, i) => ({
    key: `pay:${p.id || p.external_order_id || i}`,
    kind: p.reference_kind === 'shipping' ? 'shipping' : (p.reference_kind === 'cashback' ? 'cashback' : 'order'),
    external_order_id: p.external_order_id,
    display_reference: p.display_reference || p.external_order_id,
    order_id: p.order_id,
    order_status: p.order_status,
    net_total: p.net_received_amount,
    can_fetch_order: p.can_fetch_order === true,
    reconciliation_status: p.reconciliation_status,
    has_shipping_credit: p.concept === 'shipping_credit',
    marketplace_payment_id: p.id,
    lines: [{
      concept: p.concept,
      concept_label: p.concept_label || cashConceptLabel(p.concept, p.transaction_type),
      concept_hint: p.concept_hint || null,
      transaction_type: p.transaction_type,
      external_source_id: p.external_payment_id,
      external_reference: p.display_reference,
      net_amount: p.net_received_amount,
      marketplace_payment_id: p.id,
      reference_kind: p.reference_kind,
    }],
  }))
})

const missingOrdersInBucket = computed(() => {
  if (detail.value?.kind !== 'release_bucket') return []
  const seen = new Set()
  return bucketGroups.value.filter((g) => {
    if (!g.can_fetch_order) return false
    const key = String(g.external_order_id || '')
    if (!key || seen.has(key)) return false
    seen.add(key)
    return true
  })
})

function bucketGroupLabel(g) {
  if (g.kind === 'shipping') {
    return g.order_id
      ? `Envío del comprador · orden ${g.external_order_id}`
      : `Envío del comprador ${g.display_reference || g.external_order_id || ''}`
  }
  if (g.kind === 'cashback') return `Ajuste / cashback ${g.display_reference || ''}`
  if (g.kind === 'other') return g.display_reference || 'Otro movimiento MP'
  const id = g.external_order_id || g.display_reference || g.order_id || '—'
  const hasDispute = g.has_dispute || (g.lines || []).some((l) => l.concept === 'dispute')
  if (hasDispute) return `${id} · Reserva por reclamo`
  return id
}

function bucketGroupHint(g) {
  const hasDispute = g.has_dispute || (g.lines || []).some((l) => l.concept === 'dispute')
  if (hasDispute) {
    return 'MP retuvo el cobro por un reclamo o mediación. No está disponible en tu saldo hasta que se resuelva.'
  }
  if (g.reconciliation_status === 'shipping_release') {
    return 'Envío del comprador sin orden vinculada (no es ID de orden ML)'
  }
  if (g.reconciliation_status === 'shipping_linked') {
    return 'Envío del comprador · orden local encontrada'
  }
  if (g.reconciliation_status === 'non_order_reference') {
    return 'Referencia no-orden del CSV de liberaciones'
  }
  if (g.reconciliation_status === 'order_missing') {
    return 'Orden aún no sincronizada'
  }
  if (g.reconciliation_status === 'linked_order') {
    return 'Orden en /orders · falta cobro (collections)'
  }
  if (g.has_shipping_credit) {
    return 'Cobro + envío que pagó el comprador'
  }
  return null
}

function canShowFetchButton(g) {
  if (!detail.value?.connection?.id) return false
  return g.can_fetch_order === true
}

function salePaymentId(g) {
  if (g.marketplace_payment_id) return g.marketplace_payment_id
  const saleLine = (g.lines || []).find((l) => l.concept === 'sale' && l.marketplace_payment_id)
  return saleLine?.marketplace_payment_id || g.lines?.[0]?.marketplace_payment_id || null
}

function applyFetchedOrderToBucket(externalId, data) {
  if (detail.value?.kind !== 'release_bucket') return
  if (Array.isArray(detail.value.groups)) {
    detail.value.groups = detail.value.groups.map((g) => {
      if (String(g.external_order_id) !== String(externalId)) return g
      return {
        ...g,
        order_id: data.order_id,
        order_status: data.status,
        can_fetch_order: false,
        reconciliation_status: (data.payments_synced ?? 0) > 0 ? 'balanced' : 'linked_order',
        marketplace_payment_id: data.payment_ids?.[0] ?? g.marketplace_payment_id,
      }
    })
  }
  if (Array.isArray(detail.value.payments)) {
    detail.value.payments = detail.value.payments.map((p) => {
      if (String(p.external_order_id) !== String(externalId)) return p
      return {
        ...p,
        order_id: data.order_id,
        order_status: data.status,
        can_fetch_order: false,
        reconciliation_status: (data.payments_synced ?? 0) > 0 ? 'balanced' : 'linked_order',
        from_ledger_only: (data.payments_synced ?? 0) === 0,
        id: data.payment_ids?.[0] ?? p.id,
      }
    })
  }
}

async function fetchMissingOrder(row, { openAfter = true } = {}) {
  const externalId = row?.external_order_id
  const connectionId = detail.value?.connection?.id
  if (!externalId || !connectionId) return null
  if (fetchingOrderKey.value && !fetchingAllMissing.value) return null

  fetchingOrderKey.value = String(externalId)
  if (!fetchingAllMissing.value) fetchOrderError.value = null
  try {
    const data = await jsonFetch(route('finance.cash.fetch-order'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      body: JSON.stringify({
        connection_id: connectionId,
        external_order_id: String(externalId),
      }),
    })

    applyFetchedOrderToBucket(externalId, data)

    if (openAfter) {
      openOrderFromBucket({
        order_id: data.order_id,
        external_order_id: data.external_order_id ?? externalId,
      })
    }
    return data
  } catch (e) {
    const message = e?.message ?? 'No se pudo traer la orden desde Mercado Libre'
    if (!fetchingAllMissing.value) {
      fetchOrderError.value = message
      return null
    }
    throw Object.assign(e instanceof Error ? e : new Error(message), { message })
  } finally {
    if (!fetchingAllMissing.value) {
      fetchingOrderKey.value = null
    }
  }
}

async function fetchAllMissingOrders() {
  const connectionId = detail.value?.connection?.id
  const missing = missingOrdersInBucket.value
  if (!connectionId || missing.length === 0 || fetchingAllMissing.value) return

  fetchingAllMissing.value = true
  fetchOrderError.value = null
  fetchAllProgress.value = { done: 0, total: missing.length, failed: 0 }
  const errors = []

  try {
    for (const row of missing) {
      fetchAllProgress.value = {
        ...fetchAllProgress.value,
        current: row.external_order_id,
      }
      try {
        await fetchMissingOrder(row, { openAfter: false })
        fetchAllProgress.value.done += 1
      } catch (e) {
        fetchAllProgress.value.failed += 1
        fetchAllProgress.value.done += 1
        errors.push(`${row.external_order_id}: ${e?.message ?? 'error'}`)
      }
    }
    if (errors.length) {
      fetchOrderError.value = `Listo con errores (${errors.length}/${missing.length}). ${errors.slice(0, 3).join(' · ')}${errors.length > 3 ? '…' : ''}`
    }
  } finally {
    fetchingAllMissing.value = false
    fetchingOrderKey.value = null
    fetchAllProgress.value = null
  }
}

function onBucketGroupClick(group) {
  if (group?.order_id) {
    openOrderFromBucket(group)
    return
  }
  const paymentId = salePaymentId(group)
  if (paymentId) {
    openPaymentFromBucket(paymentId)
  }
}

async function queueSync(connectionId) {
  const id = connectionId || syncConnectionId.value
  if (!id) return
  syncMessage.value = null
  syncPanelOpen.value = true
  try {
    const data = await jsonFetch(route('finance.cash.sync'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      body: JSON.stringify({ connection_id: id, report_kind: 'settlement' }),
    })
    syncProgress.value = data.progress || null
    syncMessage.value = 'Reportes encolados. Seguimiento en vivo abajo.'
    startSyncPolling(id)
  } catch (e) {
    syncMessage.value = e?.message ?? 'No se pudo encolar el sync'
    stopSyncPolling()
  }
}

onBeforeUnmount(() => {
  stopSyncPolling()
  if (searchTimer) clearTimeout(searchTimer)
})

watch(syncConnectionId, (id) => {
  if (id) {
    loadReportFiles(id)
    if (syncProgress.value?.active || syncPolling.value) {
      startSyncPolling(id)
    }
  } else {
    reportFiles.value = []
  }
}, { immediate: true })

function closeDetail() {
  detailOpen.value = false
}

function statusTone(status) {
  if (status === 'balanced') return 'text-emerald-700'
  if (status === 'short') return 'text-rose-700'
  if (status === 'over') return 'text-amber-700'
  return 'text-muted-foreground'
}

const detailTitle = computed(() => {
  if (detail.value?.kind === 'release_bucket') return 'Liberación de dinero'
  if (detail.value?.kind === 'withdrawal' || isWithdrawals.value) return 'Retiro al banco'
  if (isPayments.value || detail.value?.kind === 'payment') return 'Cobro / IDs'
  return 'Movimiento de caja'
})

const listSingular = computed(() => {
  if (isReleases.value) return 'liberación'
  if (isWithdrawals.value) return 'retiro'
  if (isPayments.value) return 'pago'
  return 'movimiento'
})

const listPlural = computed(() => {
  if (isReleases.value) return 'liberaciones'
  if (isWithdrawals.value) return 'retiros'
  if (isPayments.value) return 'pagos'
  return 'movimientos'
})

const releaseFiles = computed(() =>
  reportFiles.value.filter((f) => f.report_kind === 'release').slice(0, 4),
)
</script>

<template>
  <Head title="Caja / pagos" />
  <AuthenticatedLayout>
    <div class="py-5">
      <div class="w-full px-4 sm:px-6 lg:px-8">
        <PageHeader
          compact
          title="Caja del canal"
          description="Pagos, liberaciones a saldo MP y retiros al banco."
        >
          <template #actions>
            <Button
              v-if="syncConnectionId"
              variant="outline"
              size="sm"
              class="h-8 gap-1.5 px-2.5 text-xs"
              :disabled="syncPolling"
              @click="queueSync(syncConnectionId)"
            >
              <RefreshCw class="size-3.5" :class="syncPolling ? 'animate-spin' : ''" />
              {{ syncPolling ? 'Sync en curso…' : 'Sync reportes' }}
            </Button>
            <Button
              v-if="reportFiles.length || syncProgress?.started_at"
              variant="ghost"
              size="sm"
              class="h-8 px-2.5 text-xs"
              @click="syncPanelOpen = !syncPanelOpen"
            >
              <FileSpreadsheet class="mr-1 size-3.5" />
              CSV
            </Button>
          </template>
        </PageHeader>

        <div class="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
          <Card
            class="cursor-pointer rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:border-amber-200"
            content-class="p-3"
            @click="filterAlerts"
          >
            <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
              Alertas de cobro
            </p>
            <p class="mt-1 flex items-center gap-2 text-2xl font-semibold tracking-tight text-slate-900">
              <AlertTriangle
                v-if="alert_count > 0"
                class="size-5 text-amber-500"
              />
              {{ alert_count }}
            </p>
            <p class="mt-0.5 text-[10px] text-muted-foreground">
              Short / over / incompletos · ver →
            </p>
          </Card>

          <Card
            class="cursor-pointer rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:border-rose-200"
            content-class="p-3"
            @click="setView('overdue')"
          >
            <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
              Liberaciones atrasadas
            </p>
            <p class="mt-1 flex items-center gap-2 text-2xl font-semibold tracking-tight text-slate-900">
              <AlertTriangle
                v-if="overdue_count > 0"
                class="size-5 text-rose-500"
              />
              {{ overdue_count.toLocaleString('es-MX') }}
            </p>
            <p class="mt-0.5 text-[10px] text-muted-foreground">
              <template v-if="overdue_kpis?.overdue_net_total">
                <MoneyText
                  :amount="overdue_kpis.overdue_net_total"
                  :currency="overdue_kpis.currency_code || 'MXN'"
                />
                · huérfanas + sin liberar →
              </template>
              <template v-else>Entregadas sin release · ver →</template>
            </p>
          </Card>

          <Card
            class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
            content-class="p-3"
          >
            <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
              Pagos conciliados
            </p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
              {{ payments_total.toLocaleString('es-MX') }}
            </p>
            <button
              type="button"
              class="mt-2 text-[11px] font-semibold text-brand hover:underline"
              @click="setView('payments')"
            >
              Ver pagos →
            </button>
          </Card>

          <Card
            class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
            content-class="p-3"
          >
            <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
              Retiros al banco
            </p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
              {{ withdrawals_total.toLocaleString('es-MX') }}
            </p>
            <button
              type="button"
              class="mt-2 text-[11px] font-semibold text-brand hover:underline"
              @click="setView('withdrawals')"
            >
              Ver retiros →
            </button>
          </Card>

          <Card
            class="rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
            content-class="p-3"
          >
            <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
              CSV recibidos
            </p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
              {{ reportFiles.length }}
            </p>
            <p class="mt-0.5 truncate text-[10px] text-muted-foreground">
              <template v-if="selectedConnection">
                {{ selectedConnection.display_name }}
              </template>
              <template v-else>
                Elige conexión y sync
              </template>
            </p>
          </Card>
        </div>

        <div
          v-if="showSyncPanel"
          class="mb-3 space-y-2 rounded-xl border border-slate-200/70 bg-white px-3 py-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-[12px] font-medium text-slate-800">
              {{ syncMessage || 'Reportes Mercado Pago' }}
            </p>
            <p v-if="syncStats" class="text-[11px] tabular-nums text-muted-foreground">
              Ledger · settlement {{ syncStats.settlement_ledger_rows?.toLocaleString('es-MX') }}
              · release {{ syncStats.release_ledger_rows?.toLocaleString('es-MX') }}
            </p>
          </div>

          <ul v-if="reportProgressRows.length" class="grid gap-2 sm:grid-cols-2">
            <li
              v-for="row in reportProgressRows"
              :key="row.key"
              class="rounded-lg border border-slate-100 bg-slate-50/80 px-3 py-2"
            >
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-[12px] font-medium text-slate-800">{{ row.label }}</span>
                <span class="text-[10px] font-semibold uppercase tracking-wide" :class="phaseTone(row.phase)">
                  {{ phaseLabel(row.phase) }}
                  <span v-if="row.phase === 'waiting_report' && row.attempt">
                    · {{ row.attempt }}/{{ row.max_attempts || '?' }}
                  </span>
                </span>
              </div>
              <p class="mt-1 text-[11px] text-slate-600">{{ row.message }}</p>
              <p v-if="row.file_name || row.rows != null" class="mt-1 truncate font-mono text-[10px] text-muted-foreground">
                <span v-if="row.file_name">{{ row.file_name }}</span>
                <span v-if="row.report_shape"> · {{ row.report_shape }}</span>
                <span v-if="row.rows != null"> · {{ row.rows }} filas</span>
              </p>
              <div v-if="fileForProgressRow(row)" class="mt-1.5 flex flex-wrap gap-2">
                <a
                  class="text-[11px] font-medium text-brand hover:underline"
                  :href="fileForProgressRow(row).download_url"
                  target="_blank"
                  rel="noopener"
                >
                  Descargar
                </a>
                <button
                  type="button"
                  class="text-[11px] font-medium text-brand hover:underline"
                  @click="openCsvPreview(fileForProgressRow(row).id)"
                >
                  Ver
                </button>
              </div>
            </li>
          </ul>

          <div
            v-if="syncProgress?.reconcile"
            class="rounded-lg border border-slate-100 bg-slate-50/80 px-3 py-2"
          >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <span class="text-[12px] font-medium text-slate-800">Reconciliación</span>
              <span
                class="text-[10px] font-semibold uppercase tracking-wide"
                :class="phaseTone(syncProgress.reconcile.phase)"
              >
                {{ phaseLabel(syncProgress.reconcile.phase) }}
              </span>
            </div>
            <p class="mt-1 text-[11px] text-slate-600">{{ syncProgress.reconcile.message }}</p>
          </div>

          <div v-if="reportFiles.length" class="border-t border-slate-100 pt-2">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
              Últimos reportes recibidos
            </p>
            <ul class="mt-1.5 divide-y divide-slate-100">
              <li
                v-for="file in reportFiles.slice(0, 6)"
                :key="file.id"
                class="flex flex-wrap items-center justify-between gap-2 py-1.5 text-[11px]"
              >
                <div class="min-w-0">
                  <span class="font-medium text-slate-800">{{ kindLabel(file.report_kind) }}</span>
                  <span class="text-muted-foreground"> · {{ file.remote_file_name }}</span>
                  <span class="text-muted-foreground">
                    · {{ file.rows_count }} filas · {{ formatBytes(file.bytes) }}
                  </span>
                </div>
                <div class="flex shrink-0 gap-2">
                  <a
                    class="font-medium text-brand hover:underline"
                    :href="file.download_url"
                    target="_blank"
                    rel="noopener"
                  >
                    Descargar
                  </a>
                  <button
                    type="button"
                    class="font-medium text-brand hover:underline"
                    @click="openCsvPreview(file.id)"
                  >
                    Ver
                  </button>
                </div>
              </li>
            </ul>
          </div>
        </div>

        <div class="mb-3 inline-flex max-w-full flex-wrap gap-0.5 rounded-full bg-slate-100/90 p-0.5">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="inline-flex h-7 items-center gap-1 rounded-full px-2.5 text-[12px] font-medium tracking-tight transition"
            :class="
              view === tab.key
                ? 'bg-white text-slate-900 shadow-[0_1px_2px_rgba(15,23,42,0.08)]'
                : 'text-slate-500 hover:text-slate-800'
            "
            @click="setView(tab.key)"
          >
            {{ tab.label }}
            <span
              v-if="tab.count != null"
              class="tabular-nums text-[11px]"
              :class="view === tab.key ? 'text-slate-500' : 'text-slate-400'"
            >
              {{ Number(tab.count).toLocaleString('es-MX') }}
            </span>
          </button>
        </div>

        <OverdueReleaseCoverageBar
          v-if="isOverdue"
          :coverage="release_coverage"
          :connections="connections"
          :connection-id="localFilters.connection_id || syncConnectionId"
          @synced="onReleaseCoverageSynced"
        />

        <p
          v-if="isOverdue && fetchOrderError"
          class="mb-2 text-xs text-rose-700"
        >
          {{ fetchOrderError }}
        </p>

        <FilterBar compact>
          <Input
            v-if="!isReleases"
            v-model="localFilters.q"
            :placeholder="isOverdue ? 'Buscar orden ML…' : 'Buscar payment, orden, source…'"
            class="h-8 max-w-md flex-1 px-2.5 text-xs shadow-none"
          />
          <select
            v-if="isPayments"
            v-model="localFilters.status"
            class="h-8 rounded-md border border-input bg-white px-2.5 text-xs"
            @change="applyFilters()"
          >
            <option value="">Todos los status</option>
            <option value="balanced">Cuadrado</option>
            <option value="short">Faltante</option>
            <option value="over">Sobrante</option>
            <option value="pending">Pendiente</option>
            <option value="incomplete">Incompleto</option>
          </select>
          <select
            v-else-if="isOverdue"
            v-model="localFilters.kind"
            class="h-8 rounded-md border border-input bg-white px-2.5 text-xs"
            @change="applyFilters()"
          >
            <option value="">Todas</option>
            <option value="orphan">Huérfanas</option>
            <option value="unreleased">Sin liberar</option>
            <option value="held">En reserva</option>
          </select>
          <select
            v-else-if="isLedger"
            v-model="localFilters.entry_type"
            class="h-8 rounded-md border border-input bg-white px-2.5 text-xs"
            @change="applyFilters()"
          >
            <option value="">Todos los tipos</option>
            <option value="settlement">Cobros</option>
            <option value="release">Liberaciones</option>
            <option value="withdrawal">Retiros al banco</option>
            <option value="refund">Reembolsos</option>
            <option value="chargeback">Contracargos</option>
            <option value="ads_charge">Publicidad</option>
            <option value="storage_charge">Storage</option>
            <option value="adjustment">Ajustes</option>
          </select>
          <select
            v-model="localFilters.connection_id"
            class="h-8 max-w-[14rem] rounded-md border border-input bg-white px-2.5 text-xs"
            :style="
              localFilters.connection_id
                ? {
                    borderColor: resolveConnectionColor(selectedConnection?.color),
                    boxShadow: `inset 3px 0 0 ${resolveConnectionColor(selectedConnection?.color)}`,
                  }
                : undefined
            "
            @change="applyFilters()"
          >
            <option value="">Todos los canales</option>
            <option
              v-for="c in connections"
              :key="c.id"
              :value="String(c.id)"
            >
              {{ connectionFilterLabel(c) }}
            </option>
          </select>
          <Button
            type="button"
            size="sm"
            variant="outline"
            class="h-8 px-2.5 text-xs"
            @click="applyFilters()"
          >
            Filtrar
          </Button>
        </FilterBar>

        <p
          v-if="viewHint"
          class="mb-3 text-[11px] leading-relaxed text-muted-foreground"
        >
          {{ viewHint }}
          <template v-if="isReleases && releaseFiles.length">
            · CSV:
            <button
              v-for="file in releaseFiles"
              :key="file.id"
              type="button"
              class="ml-1 font-medium text-brand hover:underline"
              @click="openCsvPreview(file.id)"
            >
              {{ file.remote_file_name }}
            </button>
          </template>
        </p>

        <DataTable
          compact
          sticky-head
          :is-empty="displayItems.length === 0"
          :empty-title="emptyTitle"
          :empty-description="emptyDescription"
        >
          <template #head>
            <template v-if="isPayments">
              <TableHead>Fecha</TableHead>
              <TableHead>Payment / Orden</TableHead>
              <TableHead>Canal</TableHead>
              <TableHead class="text-right">Esperado</TableHead>
              <TableHead class="text-right">Real</TableHead>
              <TableHead class="text-right">Diff</TableHead>
              <TableHead>Status</TableHead>
            </template>
            <template v-else-if="isOverdue">
              <TableHead>Entregada</TableHead>
              <TableHead>Orden</TableHead>
              <TableHead>Canal</TableHead>
              <TableHead class="text-right">Neto</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Liberación tent.</TableHead>
              <TableHead class="w-28"></TableHead>
            </template>
            <template v-else-if="isReleases">
              <TableHead>Hora</TableHead>
              <TableHead>Movimiento</TableHead>
              <TableHead>Origen</TableHead>
              <TableHead class="text-right">Órdenes</TableHead>
              <TableHead class="text-right">Total</TableHead>
            </template>
            <template v-else-if="isWithdrawals">
              <TableHead>Fecha</TableHead>
              <TableHead>Payout</TableHead>
              <TableHead>Canal</TableHead>
              <TableHead class="text-right">Monto</TableHead>
              <TableHead class="text-right">Órdenes</TableHead>
            </template>
            <template v-else>
              <TableHead>Fecha</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Source / Orden</TableHead>
              <TableHead>Canal</TableHead>
              <TableHead class="text-right">Neto</TableHead>
              <TableHead>Prov.</TableHead>
            </template>
          </template>

          <TableRow
            v-for="row in displayItems"
            :key="`${view}-${row.id}`"
            class="conn-row cursor-pointer"
            :style="connectionSurfaceStyle(connectionForRow(row)?.color)"
            @click="openRow(row)"
          >
            <template v-if="isOverdue">
              <TableCell>
                <template v-if="row.delivered_at">
                  <div
                    class="text-[12px] font-medium tracking-tight text-slate-800"
                    :title="formatDateTime(row.delivered_at)"
                  >
                    {{ formatRelativeShort(row.delivered_at) }}
                  </div>
                  <div class="text-[10px] text-muted-foreground">
                    {{ formatDateTime(row.delivered_at) }}
                  </div>
                </template>
                <span v-else>—</span>
              </TableCell>
              <TableCell>
                <StackedData
                  :primary="row.external_order_id || `#${row.order_id}`"
                  :secondary="row.status || null"
                  primary-kind="code"
                  secondary-kind="label"
                />
              </TableCell>
              <TableCell>
                <ConnectionChip
                  v-if="connectionForRow(row)"
                  :connection="connectionForRow(row)"
                />
                <span v-else class="text-[10px] text-muted-foreground">—</span>
              </TableCell>
              <TableCell class="text-right text-[12px] tabular-nums">
                <MoneyText :amount="row.expected_net_amount || row.net_received_amount" :currency="row.currency_code" />
              </TableCell>
              <TableCell>
                <Badge :variant="overdueKindVariant(row.kind)" class="text-[10px]">
                  {{ overdueKindLabel(row.kind) }}
                </Badge>
              </TableCell>
              <TableCell>
                <template v-if="row.expected_release_at || row.money_release_at">
                  <div class="text-[12px] text-slate-800">
                    {{ formatRelativeShort(row.expected_release_at || row.money_release_at) }}
                  </div>
                  <div class="text-[10px] text-muted-foreground">
                    {{ expectedReleaseSourceLabel(row.expected_release_source) || formatDateTime(row.expected_release_at || row.money_release_at) }}
                  </div>
                </template>
                <span v-else class="text-[10px] text-muted-foreground">Sin fecha</span>
              </TableCell>
              <TableCell @click.stop>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  class="h-7 px-2 text-[11px]"
                  :disabled="fetchingOrderKey === String(row.external_order_id)"
                  @click="retryOverdueSync(row)"
                >
                  <RefreshCw
                    class="mr-1 size-3"
                    :class="fetchingOrderKey === String(row.external_order_id) ? 'animate-spin' : ''"
                  />
                  {{ fetchingOrderKey === String(row.external_order_id) ? 'Sync…' : 'Reintentar' }}
                </Button>
              </TableCell>
            </template>
            <template v-else-if="isPayments">
              <TableCell>
                <template v-if="row.paid_at || row.created_at">
                  <div
                    class="text-[12px] font-medium tracking-tight text-slate-800"
                    :title="formatDateTime(row.paid_at || row.created_at)"
                  >
                    {{ formatRelativeShort(row.paid_at || row.created_at) }}
                  </div>
                  <div class="text-[10px] text-muted-foreground">
                    {{ formatDateTime(row.paid_at || row.created_at) }}
                  </div>
                </template>
                <span v-else>—</span>
              </TableCell>
              <TableCell>
                <StackedData
                  :primary="row.external_payment_id || '—'"
                  :secondary="row.order?.external_order_id || (row.order_id ? `order #${row.order_id}` : null)"
                  primary-kind="code"
                  secondary-kind="code"
                />
                <Badge
                  v-if="row.has_shipping_credit"
                  variant="outline"
                  class="mt-1 text-[10px]"
                >
                  + envío del comprador
                </Badge>
                <Badge
                  v-if="row.status === 'in_mediation' || row.in_mediation"
                  variant="outline"
                  class="mt-1 ml-1 text-[10px]"
                >
                  En mediación
                </Badge>
              </TableCell>
              <TableCell>
                <ConnectionChip
                  v-if="connectionForRow(row)"
                  :connection="connectionForRow(row)"
                />
                <span v-else class="text-[10px] text-muted-foreground">—</span>
              </TableCell>
              <TableCell class="text-right text-[12px] tabular-nums">
                <MoneyText :amount="row.expected_net_amount" :currency="row.currency_code" />
              </TableCell>
              <TableCell class="text-right text-[12px] tabular-nums">
                <MoneyText :amount="row.net_received_amount" :currency="row.currency_code" />
              </TableCell>
              <TableCell
                class="text-right text-[12px] tabular-nums"
                :class="statusTone(row.reconciliation_status)"
              >
                <MoneyText :amount="row.diff_amount" :currency="row.currency_code" />
              </TableCell>
              <TableCell>
                <Badge
                  :variant="reconciliationVariant(paymentRowStatus(row))"
                  class="text-[10px]"
                >
                  {{ reconciliationLabel(paymentRowStatus(row)) }}
                </Badge>
              </TableCell>
            </template>

            <template v-else-if="isReleases">
              <TableCell>
                <template v-if="row.release_hour">
                  <div
                    class="text-[12px] font-medium tracking-tight text-slate-800"
                    :title="formatDateTime(row.release_hour)"
                  >
                    {{ formatRelativeShort(row.release_hour) }}
                  </div>
                  <div class="text-[10px] text-muted-foreground">
                    {{ formatDateTime(row.release_hour) }}
                  </div>
                </template>
              </TableCell>
              <TableCell>
                <div class="text-[12px] font-medium text-emerald-700">{{ row.label }}</div>
                <div class="text-[10px] text-muted-foreground">
                  {{ row.entries_count }} movimientos
                </div>
              </TableCell>
              <TableCell>
                <Badge
                  :variant="row.source === 'official_release' ? 'success' : 'outline'"
                  class="text-[10px]"
                >
                  {{ row.source === 'official_release' ? 'Oficial MP' : 'Estimado' }}
                </Badge>
              </TableCell>
              <TableCell class="text-right text-[12px] tabular-nums">
                {{ row.payments_count }}
              </TableCell>
              <TableCell class="text-right text-[13px] font-semibold tabular-nums text-emerald-700">
                + <MoneyText :amount="row.net_total" :currency="row.currency_code" />
              </TableCell>
            </template>

            <template v-else-if="isWithdrawals">
              <TableCell>
                <template v-if="row.occurred_at">
                  <div
                    class="text-[12px] font-medium tracking-tight text-slate-800"
                    :title="formatDateTime(row.occurred_at)"
                  >
                    {{ formatRelativeShort(row.occurred_at) }}
                  </div>
                  <div class="text-[10px] text-muted-foreground">
                    {{ formatDateTime(row.occurred_at) }}
                  </div>
                </template>
              </TableCell>
              <TableCell>
                <StackedData
                  :primary="row.external_source_id || '—'"
                  :secondary="row.transaction_type || 'PAYOUTS'"
                  primary-kind="code"
                  secondary-kind="label"
                />
              </TableCell>
              <TableCell>
                <ConnectionChip
                  v-if="connectionForRow(row)"
                  :connection="connectionForRow(row)"
                />
                <span v-else class="text-[10px] text-muted-foreground">—</span>
              </TableCell>
              <TableCell class="text-right text-[12px] tabular-nums">
                <MoneyText :amount="row.net_amount" :currency="row.currency_code" />
              </TableCell>
              <TableCell class="text-right text-[12px] tabular-nums">
                {{ row.reconciliation_links_count ?? 0 }}
              </TableCell>
            </template>

            <template v-else>
              <TableCell>
                <template v-if="row.occurred_at || row.released_at">
                  <div
                    class="text-[12px] font-medium tracking-tight text-slate-800"
                    :title="formatDateTime(row.occurred_at || row.released_at)"
                  >
                    {{ formatRelativeShort(row.occurred_at || row.released_at) }}
                  </div>
                  <div class="text-[10px] text-muted-foreground">
                    {{ formatDateTime(row.occurred_at || row.released_at) }}
                  </div>
                </template>
              </TableCell>
              <TableCell>
                <Badge variant="outline" class="text-[10px]">
                  {{ row.concept_label || cashConceptLabel(row.concept, row.transaction_type, row.entry_type, row.external_order_id) }}
                </Badge>
                <div class="mt-0.5 text-[10px] text-muted-foreground">
                  {{ ledgerFilterLabel(row.entry_type) }}
                </div>
              </TableCell>
              <TableCell>
                <StackedData
                  :primary="row.external_source_id || '—'"
                  :secondary="row.external_order_id || null"
                  primary-kind="code"
                  secondary-kind="code"
                />
                <button
                  v-if="row.local_order_id"
                  type="button"
                  class="mt-1 text-[10px] font-medium text-brand hover:underline"
                  @click.stop="openOrderFromBucket({ order_id: row.local_order_id, external_order_id: row.external_order_id })"
                >
                  Ver orden
                </button>
              </TableCell>
              <TableCell>
                <ConnectionChip
                  v-if="connectionForRow(row)"
                  :connection="connectionForRow(row)"
                />
                <span v-else class="text-[10px] text-muted-foreground">—</span>
              </TableCell>
              <TableCell class="text-right text-[12px] tabular-nums">
                <MoneyText :amount="row.net_amount" :currency="row.currency_code" />
              </TableCell>
              <TableCell class="text-[10px] text-muted-foreground">
                {{ row.provenance }}
              </TableCell>
            </template>
          </TableRow>
        </DataTable>

        <div
          v-if="hasMorePages && !isLoadingMore"
          ref="loadMoreSentinel"
          class="h-4 w-full"
          aria-hidden="true"
        />

        <div
          v-if="isLoadingMore"
          class="flex w-full items-center justify-center gap-2 py-3 text-xs text-slate-500"
        >
          <span
            class="inline-block size-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-brand"
            aria-hidden="true"
          />
          Cargando más…
        </div>

        <InfiniteListSummary
          compact
          :total="totalCount"
          :showing="displayItems.length"
          :has-more="hasMorePages"
          :from="paginationFrom"
          :to="paginationTo"
          :singular-label="listSingular"
          :plural-label="listPlural"
        />
      </div>
    </div>

    <SlideOverShell
      :show="detailOpen"
      :title="detailTitle"
      :max-width="ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS"
      :loading="detailLoading"
      :error="detailError"
      @close="closeDetail"
    >
      <MarketplacePaymentDetail
        v-if="detail?.kind === 'payment' && detail.payment"
        :payment="detail.payment"
        :diff="detail.diff"
        :aligned="detail.aligned"
        :cashout-trail="detail.cashout_trail"
        :ledger-entries="detail.ledger_entries ?? []"
        :release-batch="detail.release_batch"
      />

      <div
        v-else-if="detail?.kind === 'release_bucket'"
        class="space-y-4 overflow-y-auto p-4 text-sm"
      >
        <div class="rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2">
          <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-800">
            Liberación de dinero
          </p>
          <p class="mt-1 text-xs text-emerald-900/80">
            {{ formatDateTime(detail.release_hour) }}
            <span v-if="detail.connection?.display_name"> · {{ detail.connection.display_name }}</span>
          </p>
          <p class="mt-2 text-lg font-semibold tabular-nums text-emerald-700">
            + <MoneyText :amount="detail.net_total" :currency="detail.currency_code" />
          </p>
          <p class="mt-1 text-[11px] text-muted-foreground">
            {{ detail.payments_count }} órdenes / {{ detail.entries_count }} movimientos
            · {{ detail.source === 'official_release' ? 'Oficial MP' : 'Estimado settlement' }}
            <span v-if="detail.truncated"> (vista truncada)</span>
          </p>
        </div>

        <div>
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-[10px] font-semibold uppercase text-muted-foreground">
              Órdenes en este lote
            </p>
            <Button
              v-if="missingOrdersInBucket.length && detail.connection?.id"
              type="button"
              size="sm"
              variant="outline"
              class="h-7 gap-1.5 px-2.5 text-[11px]"
              :disabled="fetchingAllMissing || !!fetchingOrderKey"
              @click="fetchAllMissingOrders"
            >
              <RefreshCw
                class="size-3"
                :class="fetchingAllMissing ? 'animate-spin' : ''"
              />
              <template v-if="fetchingAllMissing && fetchAllProgress">
                Trayendo {{ fetchAllProgress.done }}/{{ fetchAllProgress.total }}…
              </template>
              <template v-else>
                Traer todas ({{ missingOrdersInBucket.length }})
              </template>
            </Button>
          </div>
          <p
            v-if="fetchOrderError"
            class="mb-2 text-[11px] text-rose-700"
          >
            {{ fetchOrderError }}
          </p>
          <DataTable
            compact
            :is-empty="!bucketGroups.length"
            empty-title="Sin órdenes en el lote"
            empty-description="El CSV de liberación no trajo referencia de orden para estas filas."
          >
            <template #head>
              <TableHead>Venta</TableHead>
              <TableHead>Payment</TableHead>
              <TableHead class="text-right">Total</TableHead>
              <TableHead class="w-24 text-right"> </TableHead>
            </template>
            <TableRow
              v-for="(g, idx) in bucketGroups"
              :key="g.key || `${g.external_order_id}-${idx}`"
              :class="g.order_id || salePaymentId(g) ? 'cursor-pointer' : ''"
              @click="onBucketGroupClick(g)"
            >
              <TableCell class="text-[12px]">
                <button
                  v-if="g.order_id"
                  type="button"
                  class="text-left font-mono font-medium text-brand hover:underline"
                  @click.stop="openOrderFromBucket(g)"
                >
                  {{ bucketGroupLabel(g) }}
                </button>
                <div v-else class="font-mono">{{ bucketGroupLabel(g) }}</div>
                <Badge
                  v-if="g.has_dispute || g.reconciliation_status === 'in_mediation'"
                  variant="outline"
                  class="mt-1 text-[10px]"
                >
                  En mediación
                </Badge>
                <div
                  v-if="bucketGroupHint(g)"
                  class="text-[10px] text-muted-foreground"
                >
                  {{ bucketGroupHint(g) }}
                </div>
                <ul
                  v-if="g.lines?.length"
                  class="mt-1.5 space-y-1 border-l border-slate-200 pl-2.5"
                >
                  <li
                    v-for="(line, li) in g.lines"
                    :key="line.ledger_entry_id || `${g.key}-${li}`"
                    class="flex items-start justify-between gap-2 text-[11px]"
                  >
                    <div>
                      <span class="font-medium text-slate-700">{{ line.concept_label }}</span>
                      <span
                        v-if="line.concept_hint"
                        class="mt-0.5 block text-[10px] leading-snug text-muted-foreground"
                      >
                        {{ line.concept_hint }}
                      </span>
                    </div>
                    <span class="shrink-0 tabular-nums text-slate-800">
                      <MoneyText :amount="line.net_amount" :currency="detail.currency_code" />
                    </span>
                  </li>
                </ul>
              </TableCell>
              <TableCell class="font-mono text-[12px] text-muted-foreground">
                <button
                  v-if="salePaymentId(g)"
                  type="button"
                  class="text-left hover:underline"
                  title="Ver cobro / cash-out"
                  @click.stop="openPaymentFromBucket(salePaymentId(g))"
                >
                  {{ (g.lines || []).find((l) => l.concept === 'sale')?.external_source_id
                    || g.lines?.[0]?.external_source_id
                    || '—' }}
                </button>
                <template v-else>
                  {{ g.lines?.[0]?.external_source_id || '—' }}
                </template>
              </TableCell>
              <TableCell class="text-right tabular-nums font-medium">
                <MoneyText :amount="g.available_amount ?? g.net_total" :currency="detail.currency_code" />
                <div
                  v-if="g.has_dispute && Number(g.retained_amount) > 0"
                  class="text-[10px] font-normal text-amber-800"
                >
                  Retenido
                  <MoneyText :amount="g.retained_amount" :currency="detail.currency_code" />
                </div>
              </TableCell>
              <TableCell class="text-right" @click.stop>
                <Button
                  v-if="canShowFetchButton(g)"
                  type="button"
                  size="sm"
                  variant="outline"
                  class="h-7 gap-1 px-2 text-[11px]"
                  :disabled="fetchingAllMissing || fetchingOrderKey === String(g.external_order_id)"
                  @click="fetchMissingOrder(g)"
                >
                  <RefreshCw
                    class="size-3"
                    :class="fetchingOrderKey === String(g.external_order_id) ? 'animate-spin' : ''"
                  />
                  {{ fetchingOrderKey === String(g.external_order_id) ? 'Trayendo…' : 'Traer' }}
                </Button>
              </TableCell>
            </TableRow>
          </DataTable>
        </div>
      </div>

      <div
        v-else-if="detail?.entry"
        class="space-y-4 overflow-y-auto p-4 text-sm"
      >
        <div
          v-if="detail.is_bank_payout"
          class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"
        >
          <p class="text-[11px] font-semibold uppercase tracking-wide">Retiro al banco</p>
          <p class="mt-1 font-mono text-xs">
            Payout ID: {{ detail.payout_id || detail.entry.external_source_id || '—' }}
          </p>
          <p class="mt-2 text-base font-semibold tabular-nums">
            <MoneyText :amount="detail.entry.net_amount" :currency="detail.entry.currency_code" />
          </p>
          <p v-if="detail.entry.occurred_at" class="mt-1 text-[11px] text-muted-foreground">
            {{ formatDateTime(detail.entry.occurred_at) }}
          </p>
        </div>
        <div v-else class="grid gap-2 sm:grid-cols-2">
          <div>
            <p class="text-[10px] uppercase text-muted-foreground">Tipo</p>
            <p>{{ detail.concept_label || cashConceptLabel(detail.concept, detail.entry.transaction_type, detail.entry.entry_type) }}</p>
            <p
              v-if="detail.concept_hint"
              class="mt-0.5 text-[11px] text-muted-foreground"
            >
              {{ detail.concept_hint }}
            </p>
            <p class="mt-0.5 text-[10px] text-muted-foreground">
              {{ ledgerFilterLabel(detail.entry.entry_type) }}
            </p>
          </div>
          <div>
            <p class="text-[10px] uppercase text-muted-foreground">Neto</p>
            <MoneyText :amount="detail.entry.net_amount" :currency="detail.entry.currency_code" />
          </div>
        </div>
        <button
          v-if="detail.local_order_id"
          type="button"
          class="text-xs font-medium text-brand hover:underline"
          @click="openOrderFromBucket({ order_id: detail.local_order_id, external_order_id: detail.resolved_external_order_id })"
        >
          Ver orden {{ detail.resolved_external_order_id || detail.local_order_id }}
        </button>
        <div>
          <p class="mb-1 text-[10px] font-semibold uppercase text-muted-foreground">
            {{ detail.is_bank_payout ? 'Órdenes cubiertas por este retiro' : 'Órdenes cubiertas' }}
          </p>
          <ul v-if="detail.covered_orders?.length" class="space-y-1">
            <li
              v-for="o in detail.covered_orders"
              :key="`${o.order_id}-${o.marketplace_payment_id}-${o.match_method}`"
              class="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-100 px-2 py-1 text-xs"
            >
              <span class="font-mono">{{ o.external_order_id || o.order_id || o.external_payment_id }}</span>
              <Badge variant="outline">{{ matchMethodLabel(o.match_method) }}</Badge>
              <MoneyText :amount="o.allocated_amount ?? o.diff_amount" :currency="detail.entry.currency_code" />
            </li>
          </ul>
          <p v-else class="text-xs text-muted-foreground">
            <template v-if="detail.is_bank_payout">
              Sin atribución aún. Corre reconciliación tras marcar pagos liberados.
            </template>
            <template v-else>
              Sin match a órdenes todavía.
            </template>
          </p>
        </div>
      </div>
    </SlideOverShell>

    <SlideOverShell
      :show="!!csvPreview || csvPreviewLoading || !!csvPreviewError"
      title="CSV recibido"
      :max-width="ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS"
      :loading="csvPreviewLoading"
      :error="csvPreviewError"
      @close="closeCsvPreview"
    >
      <div v-if="csvPreview" class="space-y-3 overflow-y-auto p-4 text-sm">
        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
          <p class="font-medium text-slate-800">{{ csvPreview.file?.remote_file_name }}</p>
          <p class="mt-1 text-muted-foreground">
            {{ kindLabel(csvPreview.file?.report_kind) }}
            · {{ csvPreview.file?.report_shape || '—' }}
            · {{ csvPreview.total_rows }} filas
            · {{ formatBytes(csvPreview.file?.bytes) }}
          </p>
          <a
            v-if="csvPreview.file?.download_url"
            class="mt-2 inline-block font-medium text-brand hover:underline"
            :href="csvPreview.file.download_url"
            target="_blank"
            rel="noopener"
          >
            Descargar archivo completo
          </a>
        </div>
        <div class="overflow-x-auto rounded-xl border border-slate-200">
          <table class="min-w-full text-left text-[11px]">
            <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-muted-foreground">
              <tr>
                <th
                  v-for="h in csvPreview.headers"
                  :key="h"
                  class="whitespace-nowrap px-2 py-1.5"
                >
                  {{ h }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, idx) in csvPreview.rows"
                :key="idx"
                class="border-t border-slate-100"
              >
                <td
                  v-for="h in csvPreview.headers"
                  :key="`${idx}-${h}`"
                  class="max-w-[12rem] truncate px-2 py-1 font-mono"
                  :title="row[h] ?? ''"
                >
                  {{ row[h] ?? '' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="csvPreview.truncated" class="text-xs text-muted-foreground">
          Vista truncada (primeras {{ csvPreview.rows?.length }} filas). Descarga el CSV para ver todo.
        </p>
      </div>
    </SlideOverShell>
    <OrderDetailSlideOver
      :show="orderSlideOpen"
      :order-id="selectedOrderId"
      :external-order-id="selectedOrderExternalId"
      :connection="detail?.connection || null"
      @close="closeOrderSlide"
    />
  </AuthenticatedLayout>
</template>
