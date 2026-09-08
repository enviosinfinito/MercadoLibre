<script setup>
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import FullOperationTypePill from '@/Components/Domain/FullOperationTypePill.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime, formatRelativeShort } from '@/lib/utils'
import { ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS } from '@/lib/slideOverLayout'

const OrderDetailSlideOver = defineAsyncComponent(
  () => import('@/Components/Orders/OrderDetailSlideOver.vue'),
)
const StockDetailSlideOver = defineAsyncComponent(
  () => import('@/Components/Inventory/StockDetailSlideOver.vue'),
)
const ProductDetailSlideOver = defineAsyncComponent(
  () => import('@/Components/Products/ProductDetailSlideOver.vue'),
)

const props = defineProps({
  show: { type: Boolean, default: false },
  operationId: { type: [Number, String], default: null },
  connection: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const loading = ref(false)
const error = ref(null)
const payload = ref(null)
const rawOpen = ref(false)

const orderSlideOpen = ref(false)
const stockSlideOpen = ref(false)
const productSlideOpen = ref(false)

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  payload.value = null
  rawOpen.value = false
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('inventory.full-operations.show', id)
        : `/inventory/full-operations/${id}`
    payload.value = await fetchSlidePayload(url, { cache: 'no-store' })
  } catch (e) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo cargar el detalle de la operación Full.'
  } finally {
    loading.value = false
  }
}

function retryFetch() {
  load(props.operationId)
}

watch(
  () => [props.show, props.operationId],
  ([open, id]) => {
    if (open && id) load(id)
    if (!open) {
      payload.value = null
      error.value = null
      orderSlideOpen.value = false
      stockSlideOpen.value = false
      productSlideOpen.value = false
    }
  },
  { immediate: true },
)

const operation = computed(() => payload.value?.operation ?? null)
const related = computed(() => payload.value?.related ?? null)

const connectionForChip = computed(() => payload.value?.connection ?? props.connection ?? null)

const headerTitle = computed(() => {
  if (operation.value?.operation_type_label) return operation.value.operation_type_label
  return 'Movimiento Full'
})

const headerNumber = computed(() => {
  return operation.value?.external_operation_id ?? props.operationId ?? ''
})

function fmtQty(v) {
  if (v == null || v === '') return '—'
  const n = Number(v)
  if (Number.isNaN(n)) return String(v)
  const formatted = n.toLocaleString('es-MX', { maximumFractionDigits: 2 })
  return n > 0 ? `+${formatted}` : formatted
}

function qtyClass(v) {
  if (v == null || v === '') return 'text-slate-900'
  const n = Number(v)
  if (!Number.isFinite(n) || n === 0) return 'text-slate-900'
  return n > 0 ? 'text-emerald-700' : 'text-rose-700'
}

function fmtPlain(v) {
  if (v == null || v === '') return '—'
  const n = Number(v)
  if (Number.isNaN(n)) return String(v)
  return n.toLocaleString('es-MX', { maximumFractionDigits: 2 })
}

function notAvailableStatusLabel(status) {
  return (
    {
      damage: 'Dañado',
      damaged: 'Dañado',
      lost: 'Perdido',
      withdrawal: 'Retiro',
      internal_process: 'Proceso interno',
      transfer: 'Transferencia',
      noFiscalCoverage: 'Sin cobertura fiscal',
      not_supported: 'No procesable',
    }[status] ?? status
  )
}

function closeAll() {
  orderSlideOpen.value = false
  stockSlideOpen.value = false
  productSlideOpen.value = false
  emit('close')
}

function openOrder() {
  if (!related.value?.order?.id) return
  orderSlideOpen.value = true
}

function openStock() {
  if (!related.value?.variant?.id) return
  stockSlideOpen.value = true
}

function canOpenProduct() {
  return Boolean(
    related.value?.variant?.product_id
      || payload.value?.channel_listing?.id
      || payload.value?.channel_listing?.external_item_id,
  )
}

function openProduct() {
  if (!canOpenProduct()) return
  productSlideOpen.value = true
}

function outcomeLabel(outcome) {
  return (
    {
      returned: 'Devuelto',
      refunded: 'Reembolsado',
      partial_refunded: 'Parcial',
      claim_open: 'Reclamo abierto',
    }[outcome] ?? outcome ?? '—'
  )
}

const showReturnLinkHint = computed(() => {
  const type = operation.value?.operation_type
  if (!type || type === 'SALE_RETURN') return false
  return Boolean(related.value?.return || related.value?.order)
})
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    compact-header
    mobile-full-bleed
    fill-height
    stackable
    overlay-stack
    :max-width="ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS"
    :loading="loading"
    :error="error"
    accessibility-title="Detalle operación Full"
    @close="closeAll"
    @retry="retryFetch"
  >
    <template #header>
      <div class="min-w-0 space-y-1.5">
        <div class="flex min-w-0 items-center gap-2">
          <FullOperationTypePill
            v-if="operation"
            compact
            :label="operation.operation_type_label"
            :variant="operation.pill_variant || 'secondary'"
          />
          <h2
            class="min-w-0 truncate text-[13px] font-semibold tracking-tight text-slate-900 tabular-nums sm:text-[14px]"
            :title="`${headerTitle} #${headerNumber}`"
          >
            {{ headerTitle }}
            <span
              v-if="headerNumber"
              class="font-mono text-[12px] font-medium text-slate-500"
            >
              #{{ headerNumber }}
            </span>
          </h2>
        </div>
        <p
          v-if="operation?.occurred_at"
          class="text-[11px] text-muted-foreground"
          :title="formatDateTime(operation.occurred_at)"
        >
          {{ formatRelativeShort(operation.occurred_at) }}
          · {{ formatDateTime(operation.occurred_at) }}
        </p>
      </div>
    </template>

    <template #header-actions>
      <ConnectionChip
        v-if="connectionForChip"
        class="mr-0.5 max-w-[7.5rem] sm:max-w-[14rem]"
        :connection="connectionForChip"
        :account-only="false"
        :compact="false"
      />
    </template>

    <div
      v-if="operation"
      class="flex min-h-0 flex-1 flex-col overflow-auto px-4 py-4 sm:px-6"
    >
      <div class="mb-4 flex flex-wrap gap-1.5">
        <Badge
          v-if="operation.inventory_id"
          variant="muted"
        >
          inv {{ operation.inventory_id }}
        </Badge>
        <Badge
          v-if="operation.inbound_id"
          variant="muted"
        >
          inbound {{ operation.inbound_id }}
        </Badge>
        <Badge
          v-if="related?.marketplace_inbound"
          variant="success"
        >
          Cazado · {{ related.marketplace_inbound.status }}
        </Badge>
        <Badge
          v-if="operation.operation_type"
          variant="muted"
          class="font-mono"
        >
          {{ operation.operation_type }}
        </Badge>
      </div>

      <div class="mb-6 grid grid-cols-2 gap-3">
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-[11px] uppercase text-muted-foreground">Δ disponible</p>
          <p
            class="text-lg font-semibold tabular-nums"
            :class="qtyClass(operation.available_quantity_delta)"
          >
            {{ fmtQty(operation.available_quantity_delta) }}
          </p>
        </div>
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-[11px] uppercase text-muted-foreground">Δ no disp.</p>
          <p
            class="text-lg font-semibold tabular-nums"
            :class="qtyClass(operation.not_available_quantity_delta)"
          >
            {{ fmtQty(operation.not_available_quantity_delta) }}
          </p>
        </div>
        <div class="col-span-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-[11px] uppercase text-muted-foreground">Resultado después</p>
          <p class="text-sm font-medium">
            <template v-if="operation.result_available != null || operation.result_total != null">
              {{ fmtPlain(operation.result_available) }} disp.
              <span v-if="operation.result_not_available">
                / {{ fmtPlain(operation.result_not_available) }} no
              </span>
              <span
                v-if="operation.result_total"
                class="text-muted-foreground"
              >
                (total {{ fmtPlain(operation.result_total) }})
              </span>
            </template>
            <template v-else>—</template>
          </p>
        </div>
      </div>

      <section
        v-if="related?.variant || payload.channel_listing"
        class="mb-6"
      >
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Producto</h3>
        <div class="rounded-md border border-slate-200 px-3 py-2 text-sm">
          <button
            v-if="related?.variant && canOpenProduct()"
            type="button"
            class="block text-left font-mono text-xs text-brand hover:underline"
            @click="openProduct"
          >
            {{ related.variant.sku }}
            <span
              v-if="related.variant.name"
              class="text-muted-foreground"
            >
              · {{ related.variant.name }}
            </span>
          </button>
          <p
            v-else-if="related?.variant"
            class="font-mono text-xs"
          >
            {{ related.variant.sku }}
            <span
              v-if="related.variant.name"
              class="text-muted-foreground"
            >
              · {{ related.variant.name }}
            </span>
          </p>
          <p
            v-if="payload.channel_listing"
            class="mt-1 text-xs text-muted-foreground"
          >
            {{ payload.channel_listing.title ?? '—' }}
            <span
              v-if="payload.channel_listing.external_item_id"
              class="font-mono"
            >
              · {{ payload.channel_listing.external_item_id }}
            </span>
          </p>
          <a
            v-if="payload.channel_listing?.permalink"
            :href="payload.channel_listing.permalink"
            target="_blank"
            rel="noopener"
            class="mt-1 inline-block text-xs text-brand hover:underline"
          >
            Ver en Mercado Libre
          </a>
        </div>
      </section>

      <section
        v-if="operation.not_available_detail?.length"
        class="mb-6"
      >
        <h3 class="mb-2 text-sm font-semibold text-slate-900">No disponible (detalle)</h3>
        <ul class="divide-y divide-slate-100 rounded-md border border-slate-200">
          <li
            v-for="(row, idx) in operation.not_available_detail"
            :key="`${row.status}-${idx}`"
            class="flex items-center justify-between px-3 py-2 text-sm"
          >
            <span>{{ notAvailableStatusLabel(row.status) }}</span>
            <span class="font-mono text-xs">{{ fmtPlain(row.quantity) }}</span>
          </li>
        </ul>
      </section>

      <section
        v-if="related?.return"
        class="mb-6"
      >
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Devolución relacionada</h3>
        <div class="rounded-md border border-emerald-200 bg-emerald-50/60 px-3 py-3 text-sm">
          <div class="flex flex-wrap items-center gap-1.5">
            <Badge variant="success">{{ outcomeLabel(related.return.outcome) }}</Badge>
            <Badge variant="muted">{{ related.return.status }}</Badge>
            <span
              v-if="related.return.days_to_return != null"
              class="text-xs text-muted-foreground"
            >
              {{ related.return.days_to_return }} día(s) a devolver
            </span>
          </div>
          <p
            v-if="related.return.reason_label"
            class="mt-2 text-sm text-slate-800"
          >
            {{ related.return.reason_label }}
          </p>
          <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
            <div>
              <dt class="uppercase">Entregada</dt>
              <dd>{{ formatDateTime(related.return.delivered_at) }}</dd>
            </div>
            <div>
              <dt class="uppercase">Abierta</dt>
              <dd>{{ formatDateTime(related.return.opened_at) }}</dd>
            </div>
            <div>
              <dt class="uppercase">Cerrada</dt>
              <dd>{{ formatDateTime(related.return.closed_at) }}</dd>
            </div>
            <div v-if="related.return.returned_amount">
              <dt class="uppercase">Monto</dt>
              <dd class="font-mono">
                {{ related.return.returned_amount }} {{ related.return.currency_code || '' }}
              </dd>
            </div>
          </dl>
          <a
            :href="route('returns.items.show', related.return.id)"
            class="mt-3 inline-block text-xs font-medium text-brand hover:underline"
          >
            Ver ficha de devolución #{{ related.return.id }}
          </a>
        </div>
        <p
          v-if="showReturnLinkHint"
          class="mt-2 text-[11px] text-muted-foreground"
        >
          ML registró este movimiento como
          <span class="font-mono">{{ operation.operation_type }}</span>;
          enlazado por referencia de orden/envío
          <span v-if="related.match_via">({{ related.match_via }})</span>.
        </p>
      </section>

      <p
        v-else-if="showReturnLinkHint && related?.order"
        class="mb-6 text-[11px] text-muted-foreground"
      >
        ML registró este movimiento como
        <span class="font-mono">{{ operation.operation_type }}</span>;
        hay orden relacionada pero aún no hay caso en /returns.
      </p>

      <section
        v-if="operation.external_references?.length || related?.shipment || related?.order"
        class="mb-6"
      >
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Referencias</h3>
        <ul class="space-y-2 rounded-md border border-slate-200 px-3 py-2 text-sm">
          <li
            v-for="(ref, idx) in operation.external_references"
            :key="idx"
            class="font-mono text-xs text-slate-700"
          >
            {{ ref.type ?? 'ref' }}: {{ ref.value ?? ref.id ?? '—' }}
          </li>
          <li
            v-if="related?.shipment"
            class="text-xs text-muted-foreground"
          >
            Envío canónico:
            <span v-if="related.shipment.id">#{{ related.shipment.id }} · {{ related.shipment.status ?? '—' }}</span>
            <span v-else-if="related.shipment.unmatched">no encontrado en el sistema ({{ related.shipment.external_shipment_id }})</span>
          </li>
          <li
            v-if="related?.order"
            class="text-xs"
          >
            <button
              type="button"
              class="text-brand hover:underline"
              @click="openOrder"
            >
              Orden #{{ related.order.external_order_id }}
            </button>
            <span class="text-muted-foreground"> · {{ related.order.status }}</span>
          </li>
        </ul>
      </section>

      <section class="mb-2">
        <button
          type="button"
          class="flex w-full items-center justify-between rounded-md border border-slate-200 px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
          @click="rawOpen = !rawOpen"
        >
          JSON crudo (ML)
          <span class="text-xs font-normal text-muted-foreground">{{ rawOpen ? 'ocultar' : 'mostrar' }}</span>
        </button>
        <pre
          v-if="rawOpen"
          class="mt-2 max-h-80 overflow-auto rounded-md border border-slate-200 bg-slate-950 p-3 text-[11px] leading-relaxed text-slate-100"
        >{{ JSON.stringify(operation.raw, null, 2) }}</pre>
      </section>
    </div>

    <template #footer>
      <ActionBar
        preset="slide"
        position="sticky-bottom"
        :show-informative-pages="false"
      >
        <ActionGroup align="end">
          <ActionButton
            v-if="related?.variant?.id"
            variant="secondary"
            @click="openStock"
          >
            Ver stock
          </ActionButton>
          <ActionButton
            v-if="canOpenProduct()"
            variant="secondary"
            @click="openProduct"
          >
            Ver producto
          </ActionButton>
          <ActionButton
            v-if="related?.return?.id"
            variant="secondary"
            :href="route('returns.items.show', related.return.id)"
            :inertia="true"
          >
            Ver devolución
          </ActionButton>
          <ActionButton
            v-if="related?.order?.id"
            variant="primary"
            @click="openOrder"
          >
            Ver orden
          </ActionButton>
        </ActionGroup>
      </ActionBar>
    </template>
  </SlideOverShell>

  <OrderDetailSlideOver
    :show="orderSlideOpen"
    :order-id="related?.order?.id ?? null"
    :external-order-id="related?.order?.external_order_id ?? null"
    :connection="payload?.connection ?? connectionForChip"
    @close="orderSlideOpen = false"
  />

  <StockDetailSlideOver
    :show="stockSlideOpen"
    :variant-id="related?.variant?.id ?? undefined"
    @close="stockSlideOpen = false"
  />

  <ProductDetailSlideOver
    :show="productSlideOpen"
    :product-id="related?.variant?.product_id ?? null"
    :listing-id="payload?.channel_listing?.id ?? null"
    :ml-item-id="payload?.channel_listing?.external_item_id ?? null"
    :initial-tab="payload?.channel_listing?.external_item_id ? 'publication' : 'product'"
    @close="productSlideOpen = false"
  />
</template>
