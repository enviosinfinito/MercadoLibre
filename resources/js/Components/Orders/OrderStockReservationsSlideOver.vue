<script setup>
import { defineAsyncComponent, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime } from '@/lib/utils'

const StockDetailSlideOver = defineAsyncComponent(
  () => import('@/Components/Inventory/StockDetailSlideOver.vue'),
)
const FullOperationDetailSlideOver = defineAsyncComponent(
  () => import('@/Components/Inventory/FullOperationDetailSlideOver.vue'),
)

const props = defineProps({
  show: { type: Boolean, default: false },
  orderId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close'])

const loading = ref(false)
const error = ref(null)
const order = ref(null)
const reservations = ref([])
const fullOperations = ref([])
const selectedVariantId = ref(null)
const selectedFullOpId = ref(null)

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  order.value = null
  reservations.value = []
  fullOperations.value = []
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('orders.reservations', id)
        : `/orders/${id}/reservations`
    const data = await fetchSlidePayload(url, { cache: 'no-store' })
    order.value = data.order
    reservations.value = data.reservations ?? []
    fullOperations.value = data.full_operations ?? []
  } catch (e) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudieron cargar las reservas.'
  } finally {
    loading.value = false
  }
}

function retryFetch() {
  load(props.orderId)
}

function fmtDelta(v) {
  if (v == null || v === '') return '—'
  const n = Number(v)
  if (Number.isNaN(n)) return v
  const formatted = n.toLocaleString('es-MX', { maximumFractionDigits: 2 })
  return n > 0 ? `+${formatted}` : formatted
}

watch(
  () => [props.show, props.orderId],
  ([open, id]) => {
    if (open && id) load(id)
    if (!open) {
      order.value = null
      reservations.value = []
      fullOperations.value = []
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
    accessibility-title="Stock reservado"
    @close="emit('close')"
    @retry="retryFetch"
  >
    <div class="flex min-h-0 flex-1 flex-col overflow-auto px-4 py-4 sm:px-6">
      <div class="mb-4">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Stock</p>
        <h2 class="text-lg font-semibold text-slate-900">
          Stock reservado · #{{ order?.external_order_id ?? orderId }}
        </h2>
      </div>

      <div
        v-if="reservations.length === 0 && fullOperations.length === 0"
        class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-neutral-500"
      >
        No hay reservas internas ni movimientos Full enlazados a esta orden.
      </div>

      <div v-else class="space-y-6">
        <section v-if="reservations.length > 0">
          <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
            Reservas internas
          </h3>
          <ul class="space-y-3">
            <li
              v-for="row in reservations"
              :key="row.id"
              class="cursor-pointer rounded-lg border border-slate-200 px-3 py-3 hover:bg-slate-50"
              @click="row.variant_id && (selectedVariantId = row.variant_id)"
            >
              <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p class="font-mono text-xs text-slate-500">
                    {{ row.order_line?.sku || row.variant_sku || 'Sin SKU' }}
                  </p>
                  <p class="mt-0.5 text-sm font-medium text-slate-900">
                    {{ row.order_line?.title || 'Línea de orden' }}
                  </p>
                </div>
                <Badge variant="secondary" class="capitalize">{{ row.status }}</Badge>
              </div>
              <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                <div>
                  <dt class="text-xs text-muted-foreground">Cantidad reservada</dt>
                  <dd class="mt-0.5 text-sm">{{ row.quantity }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted-foreground">Almacén</dt>
                  <dd class="mt-0.5 text-sm">
                    {{
                      row.warehouse
                        ? `${row.warehouse.name}${row.warehouse.code ? ` (${row.warehouse.code})` : ''}`
                        : '—'
                    }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-muted-foreground">Reservado</dt>
                  <dd class="mt-0.5 text-sm">
                    {{ row.reserved_at ? formatDateTime(row.reserved_at) : '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-muted-foreground">Match</dt>
                  <dd class="mt-0.5 text-sm capitalize">
                    {{ row.order_line?.match_status ?? '—' }}
                  </dd>
                </div>
              </dl>
            </li>
          </ul>
        </section>

        <section v-if="fullOperations.length > 0">
          <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
            Movimientos Full
          </h3>
          <ul class="space-y-3">
            <li
              v-for="op in fullOperations"
              :key="op.id"
              class="cursor-pointer rounded-lg border border-slate-200 px-3 py-3 hover:bg-slate-50"
              @click="selectedFullOpId = op.id"
            >
              <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p class="text-sm font-medium text-slate-900">
                    {{ op.operation_type_label }}
                  </p>
                  <p class="font-mono text-xs text-slate-500">
                    {{ op.operation_type }}
                    <span v-if="op.inventory_id"> · inv {{ op.inventory_id }}</span>
                  </p>
                  <p v-if="op.variant_sku" class="mt-0.5 font-mono text-xs text-slate-500">
                    {{ op.variant_sku }}
                  </p>
                </div>
                <Badge variant="secondary">Full</Badge>
              </div>
              <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                <div>
                  <dt class="text-xs text-muted-foreground">Ocurrido</dt>
                  <dd class="mt-0.5 text-sm">
                    {{ op.occurred_at ? formatDateTime(op.occurred_at) : '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-muted-foreground">Δ disponible</dt>
                  <dd class="mt-0.5 font-mono text-sm">{{ fmtDelta(op.available_quantity_delta) }}</dd>
                </div>
              </dl>
            </li>
          </ul>
        </section>
      </div>
    </div>

    <template #footer>
      <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
        <template #end>
          <ActionGroup>
            <ActionButton label="Cerrar" variant="brand" @click="emit('close')" />
          </ActionGroup>
        </template>
      </ActionBar>
    </template>
  </SlideOverShell>

  <StockDetailSlideOver
    :show="selectedVariantId != null"
    :variant-id="selectedVariantId"
    @close="selectedVariantId = null"
  />
  <FullOperationDetailSlideOver
    :show="selectedFullOpId != null"
    :operation-id="selectedFullOpId"
    @close="selectedFullOpId = null"
  />
</template>
