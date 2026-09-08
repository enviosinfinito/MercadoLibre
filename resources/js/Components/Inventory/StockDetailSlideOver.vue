<script setup>
import { defineAsyncComponent, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import {
  formatStockDepletionHint,
  formatStockDepletionLabel,
  stockDepletionSeverity,
} from '@/lib/stockForecast'
import { formatDateTime } from '@/lib/utils'
import { router, useForm } from '@inertiajs/vue3'
import VariantInventoryTimeline from '@/Components/Inventory/VariantInventoryTimeline.vue'

const FullOperationDetailSlideOver = defineAsyncComponent(
  () => import('@/Components/Inventory/FullOperationDetailSlideOver.vue'),
)

const props = defineProps({
  show: { type: Boolean, default: false },
  variantId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close', 'changed'])

const loading = ref(false)
const error = ref(null)
const payload = ref(null)
const actionMode = ref(null) // adjust | transfer | ship_full | return_full | null
const fullOpSlideId = ref(null)

const adjustForm = useForm({
  warehouse_id: null,
  quantity_delta: '0',
  notes: '',
})

const transferForm = useForm({
  from_warehouse_id: null,
  to_warehouse_id: null,
  quantity: '1',
  notes: '',
})

const shipFullForm = useForm({
  connection_id: null,
  from_warehouse_id: null,
  quantity: '1',
  external_inbound_id: '',
  notes: '',
})

const returnFullForm = useForm({
  warehouse_id: null,
  quantity: '1',
  full_stock_operation_id: null,
  marketplace_inbound_id: null,
  notes: '',
})

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  payload.value = null
  actionMode.value = null
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('stock.show', id)
        : `/stock/${id}`
    payload.value = await fetchSlidePayload(url, { cache: 'no-store' })
    const warehouses = payload.value?.warehouses ?? []
    const defaultWh = warehouses.find((w) => w.is_default)?.id ?? warehouses[0]?.id ?? null
    adjustForm.warehouse_id = defaultWh
    transferForm.from_warehouse_id = defaultWh
    transferForm.to_warehouse_id = warehouses.find((w) => w.id !== defaultWh)?.id ?? defaultWh
    shipFullForm.from_warehouse_id = defaultWh
    shipFullForm.connection_id = payload.value?.connections?.[0]?.id ?? null
    returnFullForm.warehouse_id = defaultWh
  } catch (e) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo cargar el detalle de stock.'
  } finally {
    loading.value = false
  }
}

function retryFetch() {
  load(props.variantId)
}

function afterMutation() {
  emit('changed')
  load(props.variantId)
}

function submitAdjust() {
  adjustForm.post(route('stock.adjust', props.variantId), {
    preserveScroll: true,
    onSuccess: () => {
      actionMode.value = null
      adjustForm.reset('quantity_delta', 'notes')
      afterMutation()
    },
  })
}

function submitTransfer() {
  transferForm.post(route('stock.transfer', props.variantId), {
    preserveScroll: true,
    onSuccess: () => {
      actionMode.value = null
      transferForm.reset('quantity', 'notes')
      afterMutation()
    },
  })
}

function submitShipFull() {
  shipFullForm.post(route('stock.ship-to-full', props.variantId), {
    preserveScroll: true,
    onSuccess: () => {
      actionMode.value = null
      shipFullForm.reset('quantity', 'external_inbound_id', 'notes')
      afterMutation()
    },
  })
}

function submitReturnFull() {
  returnFullForm.post(route('stock.return-from-full', props.variantId), {
    preserveScroll: true,
    onSuccess: () => {
      actionMode.value = null
      returnFullForm.reset('quantity', 'notes', 'full_stock_operation_id', 'marketplace_inbound_id')
      afterMutation()
    },
  })
}

function openPurchaseOrder(id) {
  window.location.href = route('inventory.purchase-orders.show', id)
}

function openOrder(id) {
  window.location.href = route('orders.index', { order: id })
}

function releaseReservation(id) {
  router.post(
    route('stock.reservations.release', id),
    {},
    {
      preserveScroll: true,
      onSuccess: afterMutation,
    },
  )
}

watch(
  () => [props.show, props.variantId],
  ([open, id]) => {
    if (open && id) load(id)
    if (!open) {
      payload.value = null
      error.value = null
      actionMode.value = null
    }
  },
  { immediate: true },
)

function fmtQty(v) {
  if (v == null) return '—'
  return Number(v).toLocaleString('es-MX', { maximumFractionDigits: 2 })
}

function forecastLabel(forecast) {
  return formatStockDepletionLabel(forecast)
}

function forecastHint(forecast) {
  return formatStockDepletionHint(forecast)
}

function forecastTone(forecast) {
  return stockDepletionSeverity(forecast)
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
    accessibility-title="Detalle de stock"
    @close="emit('close')"
    @retry="retryFetch"
  >
    <div
      v-if="payload?.variant"
      class="flex min-h-0 flex-1 flex-col overflow-auto px-4 py-4 sm:px-6"
    >
      <div class="mb-4">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Stock</p>
        <h2 class="font-mono text-lg font-semibold text-slate-900">
          {{ payload.variant.sku }}
        </h2>
        <p class="text-sm text-slate-600">{{ payload.variant.name ?? '—' }}</p>
        <div class="mt-2 flex flex-wrap gap-1.5">
          <Badge
            v-if="payload.variant.flags?.negative"
            variant="danger"
          >
            Negativo
          </Badge>
          <Badge
            v-if="payload.variant.flags?.low_stock"
            variant="warning"
          >
            Bajo stock
          </Badge>
          <Badge
            v-if="payload.variant.flags?.unmatched"
            variant="muted"
          >
            Sin match
          </Badge>
          <Badge
            v-if="payload.variant.flags?.channel_mismatch"
            variant="warning"
          >
            Desfase canal
          </Badge>
        </div>
      </div>

      <div class="mb-6 grid grid-cols-3 gap-3">
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-[11px] uppercase text-muted-foreground">On hand</p>
          <p class="text-lg font-semibold">{{ fmtQty(payload.variant.quantity_on_hand) }}</p>
        </div>
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-[11px] uppercase text-muted-foreground">Reservado</p>
          <p class="text-lg font-semibold">{{ fmtQty(payload.variant.quantity_reserved) }}</p>
        </div>
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-[11px] uppercase text-muted-foreground">Disponible</p>
          <p class="text-lg font-semibold">{{ fmtQty(payload.variant.quantity_available) }}</p>
        </div>
      </div>

      <div
        v-if="payload.variant.forecast"
        class="mb-6 rounded-md border border-slate-200 bg-white px-3 py-2.5"
      >
        <p class="text-[11px] uppercase text-muted-foreground">Se acaba en</p>
        <p
          class="text-lg font-semibold tabular-nums"
          :class="{
            'text-rose-700': forecastTone(payload.variant.forecast) === 'critical',
            'text-amber-700': forecastTone(payload.variant.forecast) === 'warning',
            'text-slate-900': !forecastTone(payload.variant.forecast),
          }"
          :title="forecastHint(payload.variant.forecast)"
        >
          {{ forecastLabel(payload.variant.forecast) }}
        </p>
        <p class="mt-0.5 text-[11px] text-muted-foreground">
          {{ forecastHint(payload.variant.forecast) }}
          <template v-if="payload.variant.forecast.stockout_date && payload.variant.forecast.days_of_cover > 0">
            · {{ payload.variant.forecast.stockout_date }}
          </template>
        </p>
      </div>

      <section class="mb-6">
        <div class="mb-2 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-slate-900">Por almacén</h3>
          <div class="flex gap-2">
            <Button
              type="button"
              size="sm"
              variant="outline"
              @click="actionMode = actionMode === 'adjust' ? null : 'adjust'"
            >
              Ajuste
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              @click="actionMode = actionMode === 'transfer' ? null : 'transfer'"
            >
              Transferir
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              @click="actionMode = actionMode === 'ship_full' ? null : 'ship_full'"
            >
              Enviar a Full
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              @click="actionMode = actionMode === 'return_full' ? null : 'return_full'"
            >
              Devolver de Full
            </Button>
          </div>
        </div>

        <div
          v-if="actionMode === 'adjust'"
          class="mb-3 space-y-2 rounded-md border border-slate-200 p-3"
        >
          <select
            v-model="adjustForm.warehouse_id"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          >
            <option
              v-for="w in payload.warehouses"
              :key="w.id"
              :value="w.id"
            >
              {{ w.code }} — {{ w.name }}
            </option>
          </select>
          <input
            v-model="adjustForm.quantity_delta"
            type="number"
            step="any"
            placeholder="Delta (+/-)"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          />
          <input
            v-model="adjustForm.notes"
            type="text"
            placeholder="Notas"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          />
          <Button
            type="button"
            size="sm"
            :disabled="adjustForm.processing"
            @click="submitAdjust"
          >
            Aplicar ajuste
          </Button>
        </div>

        <div
          v-if="actionMode === 'transfer'"
          class="mb-3 space-y-2 rounded-md border border-slate-200 p-3"
        >
          <select
            v-model="transferForm.from_warehouse_id"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          >
            <option
              v-for="w in payload.warehouses"
              :key="'from-' + w.id"
              :value="w.id"
            >
              Desde: {{ w.code }}
            </option>
          </select>
          <select
            v-model="transferForm.to_warehouse_id"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          >
            <option
              v-for="w in payload.warehouses"
              :key="'to-' + w.id"
              :value="w.id"
            >
              Hacia: {{ w.code }}
            </option>
          </select>
          <input
            v-model="transferForm.quantity"
            type="number"
            min="0"
            step="any"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          />
          <Button
            type="button"
            size="sm"
            :disabled="transferForm.processing"
            @click="submitTransfer"
          >
            Transferir
          </Button>
        </div>

        <div
          v-if="actionMode === 'ship_full'"
          class="mb-3 space-y-2 rounded-md border border-slate-200 p-3"
        >
          <select
            v-model="shipFullForm.connection_id"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          >
            <option
              v-for="c in payload.connections ?? []"
              :key="c.id"
              :value="c.id"
            >
              {{ c.display_name || c.external_user_id }}
            </option>
          </select>
          <select
            v-model="shipFullForm.from_warehouse_id"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          >
            <option
              v-for="w in payload.warehouses"
              :key="'ship-' + w.id"
              :value="w.id"
            >
              {{ w.code }} — {{ w.name }}
            </option>
          </select>
          <input
            v-model="shipFullForm.quantity"
            type="number"
            min="0"
            step="any"
            placeholder="Cantidad"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          />
          <input
            v-model="shipFullForm.external_inbound_id"
            type="text"
            placeholder="Inbound ID (opcional)"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          />
          <Button
            type="button"
            size="sm"
            :disabled="shipFullForm.processing"
            @click="submitShipFull"
          >
            Enviar a Full
          </Button>
        </div>

        <div
          v-if="actionMode === 'return_full'"
          class="mb-3 space-y-2 rounded-md border border-slate-200 p-3"
        >
          <select
            v-model="returnFullForm.warehouse_id"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          >
            <option
              v-for="w in payload.warehouses"
              :key="'ret-' + w.id"
              :value="w.id"
            >
              {{ w.code }} — {{ w.name }}
            </option>
          </select>
          <input
            v-model="returnFullForm.quantity"
            type="number"
            min="0"
            step="any"
            placeholder="Cantidad"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          />
          <select
            v-if="payload.full_withdrawals?.length"
            v-model="returnFullForm.full_stock_operation_id"
            class="flex h-9 w-full rounded-md border border-input bg-white px-2 text-sm"
          >
            <option :value="null">Sin operación Full</option>
            <option
              v-for="op in payload.full_withdrawals"
              :key="op.id"
              :value="op.id"
            >
              {{ op.operation_type }} · {{ op.available_quantity_delta ?? '—' }}
            </option>
          </select>
          <Button
            type="button"
            size="sm"
            :disabled="returnFullForm.processing"
            @click="submitReturnFull"
          >
            Devolver de Full
          </Button>
        </div>

        <div
          v-if="!payload.variant.warehouses?.length"
          class="text-sm text-muted-foreground"
        >
          Sin balances en almacenes.
        </div>
        <ul
          v-else
          class="divide-y divide-slate-100 rounded-md border border-slate-200"
        >
          <li
            v-for="w in payload.variant.warehouses"
            :key="w.warehouse_id"
            class="flex items-center justify-between px-3 py-2 text-sm"
          >
            <span class="font-medium">{{ w.code }} · {{ w.name }}</span>
            <span class="font-mono text-xs text-slate-600">
              OH {{ fmtQty(w.quantity_on_hand) }} · R {{ fmtQty(w.quantity_reserved) }} · A
              {{ fmtQty(w.quantity_available) }}
            </span>
          </li>
        </ul>
      </section>

      <section class="mb-6">
        <VariantInventoryTimeline
          :timeline="payload.timeline ?? []"
          :variant-id="props.variantId"
          @open-purchase-order="openPurchaseOrder"
          @open-full-operation="(id) => (fullOpSlideId = id)"
          @open-order="openOrder"
        />
      </section>

      <section class="mb-6">
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Canales</h3>
        <div
          v-if="!payload.variant.channels?.length"
          class="text-sm text-muted-foreground"
        >
          Sin publicaciones vinculadas.
        </div>
        <ul
          v-else
          class="space-y-2"
        >
          <li
            v-for="ch in payload.variant.channels"
            :key="ch.channel_listing_variant_id"
            class="rounded-md border border-slate-200 px-3 py-2 text-sm"
          >
            <div class="flex items-center justify-between">
              <span class="font-semibold capitalize">{{ ch.provider }}</span>
              <Badge variant="muted">{{ ch.listing_status ?? '—' }}</Badge>
            </div>
            <p class="mt-1 font-mono text-xs text-slate-600">
              {{ ch.external_item_id }} · qty {{ ch.available_quantity ?? '—' }}
            </p>
            <p
              v-if="ch.stock_synced_at"
              class="text-[11px] text-muted-foreground"
            >
              Sync {{ formatDateTime(ch.stock_synced_at) }}
            </p>
          </li>
        </ul>

        <div
          v-if="payload.channel_stock_locations?.length"
          class="mt-3"
        >
          <h4 class="mb-1 text-xs font-semibold uppercase text-muted-foreground">
            Stock Mercado Libre
          </h4>
          <ul class="space-y-1 text-sm">
            <li
              v-for="loc in payload.channel_stock_locations"
              :key="loc.id"
              class="flex justify-between rounded border border-slate-100 px-2 py-1.5"
            >
              <span>
                {{ loc.location_type }}
                <Badge
                  v-if="loc.location_type === 'meli_facility'"
                  variant="muted"
                  class="ml-1"
                >
                  Full (solo lectura)
                </Badge>
              </span>
              <span class="font-mono">{{ fmtQty(loc.quantity) }}</span>
            </li>
          </ul>
        </div>
      </section>

      <section class="mb-6">
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Reservas activas</h3>
        <div
          v-if="!payload.reservations?.length"
          class="text-sm text-muted-foreground"
        >
          Ninguna.
        </div>
        <ul
          v-else
          class="divide-y divide-slate-100 rounded-md border border-slate-200"
        >
          <li
            v-for="r in payload.reservations"
            :key="r.id"
            class="flex items-center justify-between gap-2 px-3 py-2 text-sm"
          >
            <div>
              <p>
                Orden #{{ r.order_id }} · {{ fmtQty(r.quantity) }}
                <span
                  v-if="r.warehouse"
                  class="text-muted-foreground"
                >
                  · {{ r.warehouse.code }}
                </span>
              </p>
              <p class="text-[11px] text-muted-foreground">
                {{ formatDateTime(r.reserved_at) }}
              </p>
            </div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              @click="releaseReservation(r.id)"
            >
              Liberar
            </Button>
          </li>
        </ul>
      </section>

      <section class="mb-6">
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Movimientos recientes</h3>
        <ul
          v-if="payload.ledger?.length"
          class="divide-y divide-slate-100 rounded-md border border-slate-200 text-sm"
        >
          <li
            v-for="m in payload.ledger"
            :key="m.id"
            class="flex justify-between px-3 py-2"
          >
            <span>
              <span class="font-medium capitalize">{{ m.movement_type }}</span>
              <span class="ml-2 font-mono text-xs">{{ m.quantity_delta }}</span>
            </span>
            <span class="text-[11px] text-muted-foreground">{{ formatDateTime(m.occurred_at) }}</span>
          </li>
        </ul>
        <p
          v-else
          class="text-sm text-muted-foreground"
        >
          Sin movimientos.
        </p>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Capas de costo abiertas</h3>
        <ul
          v-if="payload.cost_layers?.length"
          class="divide-y divide-slate-100 rounded-md border border-slate-200 text-sm"
        >
          <li
            v-for="layer in payload.cost_layers"
            :key="layer.id"
            class="flex justify-between px-3 py-2"
          >
            <span>
              {{ fmtQty(layer.qty_remaining) }} / {{ fmtQty(layer.qty_original) }}
              · {{ layer.unit_cost_amount }} {{ layer.unit_cost_currency }}
            </span>
            <span class="text-[11px] text-muted-foreground">{{ formatDateTime(layer.received_at) }}</span>
          </li>
        </ul>
        <p
          v-else
          class="text-sm text-muted-foreground"
        >
          Sin capas abiertas.
        </p>
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
            variant="secondary"
            @click="emit('close')"
          >
            Cerrar
          </ActionButton>
        </ActionGroup>
      </ActionBar>
    </template>
  </SlideOverShell>

  <FullOperationDetailSlideOver
    :show="fullOpSlideId != null"
    :operation-id="fullOpSlideId"
    @close="fullOpSlideId = null"
  />
</template>
