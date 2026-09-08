<script setup>
import { ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime } from '@/lib/utils'

const props = defineProps({
  show: { type: Boolean, default: false },
  channelListingVariantId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close', 'open-variant'])

const loading = ref(false)
const error = ref(null)
const payload = ref(null)

async function load(id) {
  if (!id) return
  loading.value = true
  error.value = null
  payload.value = null
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('stock.marketplace.show', id)
        : `/stock/marketplace/${id}`
    payload.value = await fetchSlidePayload(url, { cache: 'no-store' })
  } catch (e) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo cargar el detalle de ML.'
  } finally {
    loading.value = false
  }
}

function retryFetch() {
  load(props.channelListingVariantId)
}

watch(
  () => [props.show, props.channelListingVariantId],
  ([open, id]) => {
    if (open && id) load(id)
    if (!open) {
      payload.value = null
      error.value = null
    }
  },
  { immediate: true },
)

function fmtQty(v) {
  if (v == null || v === '') return '—'
  return Number(v).toLocaleString('es-MX', { maximumFractionDigits: 2 })
}

function locationLabel(type) {
  return (
    {
      seller_warehouse: 'Seller warehouse',
      meli_facility: 'Full (meli_facility)',
      selling_address: 'Selling address',
    }[type] ?? type
  )
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
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    mobile-full-bleed
    fill-height
    :loading="loading"
    :error="error"
    accessibility-title="Stock Mercado Libre"
    @close="emit('close')"
    @retry="retryFetch"
  >
    <div
      v-if="payload?.channel_listing_variant"
      class="flex min-h-0 flex-1 flex-col overflow-auto px-4 py-4 sm:px-6"
    >
      <div class="mb-4">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
          Publicación Mercado Libre
        </p>
        <h2 class="text-lg font-semibold text-slate-900">
          {{ payload.channel_listing_variant.title ?? 'Sin título' }}
        </h2>
        <p class="font-mono text-xs text-slate-600">
          {{ payload.channel_listing_variant.external_item_id }}
          <span v-if="payload.channel_listing_variant.sku">
            · {{ payload.channel_listing_variant.sku }}
          </span>
        </p>
        <div class="mt-2 flex flex-wrap gap-1.5">
          <Badge variant="muted">{{ payload.channel_listing_variant.listing_status ?? '—' }}</Badge>
          <Badge variant="muted">{{ payload.channel_listing_variant.logistic_type ?? '—' }}</Badge>
          <Badge :variant="payload.channel_listing_variant.matched ? 'success' : 'warning'">
            {{ payload.channel_listing_variant.matched ? 'Matched' : 'Sin match interno' }}
          </Badge>
        </div>
      </div>

      <div class="mb-6 grid grid-cols-2 gap-3">
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-[11px] uppercase text-muted-foreground">Publicado</p>
          <p class="text-lg font-semibold">
            {{ fmtQty(payload.channel_listing_variant.published_quantity) }}
          </p>
        </div>
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-[11px] uppercase text-muted-foreground">User product</p>
          <p class="truncate font-mono text-xs">
            {{ payload.channel_listing_variant.user_product_id ?? '—' }}
          </p>
          <p class="truncate font-mono text-[10px] text-muted-foreground">
            inv {{ payload.channel_listing_variant.inventory_id ?? '—' }}
          </p>
        </div>
      </div>

      <section class="mb-6">
        <h3 class="mb-2 text-sm font-semibold text-slate-900">Stock por ubicación ML</h3>
        <ul
          v-if="payload.channel_stock_locations?.length"
          class="divide-y divide-slate-100 rounded-md border border-slate-200"
        >
          <li
            v-for="loc in payload.channel_stock_locations"
            :key="loc.id"
            class="flex items-center justify-between px-3 py-2 text-sm"
          >
            <div>
              <p class="font-medium">{{ locationLabel(loc.location_type) }}</p>
              <p
                v-if="loc.store_id || loc.network_node_id"
                class="font-mono text-[10px] text-muted-foreground"
              >
                {{ loc.store_id || '—' }} / {{ loc.network_node_id || '—' }}
              </p>
              <p
                v-if="loc.location_type === 'meli_facility'"
                class="text-[11px] text-amber-700"
              >
                Solo lectura (Full)
              </p>
            </div>
            <div class="text-right">
              <p class="font-semibold">{{ fmtQty(loc.quantity) }}</p>
              <p
                v-if="loc.not_available_quantity"
                class="text-[11px] text-muted-foreground"
              >
                no disp. {{ fmtQty(loc.not_available_quantity) }}
              </p>
              <ul
                v-if="loc.not_available_detail?.length"
                class="mt-1 space-y-0.5 text-[10px] text-muted-foreground"
              >
                <li
                  v-for="(row, idx) in loc.not_available_detail"
                  :key="`${loc.id}-${row.status}-${idx}`"
                >
                  {{ notAvailableStatusLabel(row.status) }}: {{ fmtQty(row.quantity) }}
                </li>
              </ul>
              <p class="text-[10px] text-muted-foreground">
                {{ formatDateTime(loc.synced_at) }}
              </p>
            </div>
          </li>
        </ul>
        <p
          v-else
          class="text-sm text-muted-foreground"
        >
          Sin ubicaciones sincronizadas. Usa “Sincronizar warehouses ML”.
        </p>
      </section>

      <p
        v-if="payload.channel_listing_variant.permalink"
        class="text-sm"
      >
        <a
          :href="payload.channel_listing_variant.permalink"
          target="_blank"
          rel="noopener"
          class="text-brand hover:underline"
        >
          Ver en Mercado Libre
        </a>
      </p>
    </div>

    <template #footer>
      <ActionBar
        preset="slide"
        position="sticky-bottom"
        :show-informative-pages="false"
      >
        <ActionGroup align="end">
          <ActionButton
            v-if="payload?.channel_listing_variant?.variant_id"
            variant="secondary"
            @click="emit('open-variant', payload.channel_listing_variant.variant_id)"
          >
            Ver inventario interno
          </ActionButton>
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
</template>
