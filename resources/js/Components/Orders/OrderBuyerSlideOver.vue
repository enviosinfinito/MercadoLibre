<script setup>
import { computed, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import OrderStatusPill from '@/Components/Domain/OrderStatusPill.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { formatDateTime, formatRelativeShort } from '@/lib/utils'

const props = defineProps({
  show: { type: Boolean, default: false },
  order: { type: Object, default: null },
})

const emit = defineEmits(['close', 'open-order'])

const loading = ref(false)
const error = ref(null)
const payload = ref(null)

const fallbackBuyer = computed(() => {
  const meta = props.order?.meta?.buyer
  return meta && typeof meta === 'object' ? meta : null
})

const buyer = computed(() => payload.value?.buyer ?? fallbackBuyer.value)

const insights = computed(() => payload.value?.insights ?? null)
const recentOrders = computed(() => payload.value?.orders ?? [])

const fullName = computed(() => {
  const first = buyer.value?.first_name ?? ''
  const last = buyer.value?.last_name ?? ''
  const name = `${first} ${last}`.trim()
  return name || null
})

function formatPhone(phone) {
  if (!phone || typeof phone !== 'object') return null
  const area = String(phone.area_code ?? '').trim()
  const number = String(phone.number ?? '').trim()
  if (!area && !number) return null
  return `${area} ${number}`.trim()
}

const phone = computed(() => formatPhone(buyer.value?.phone))
const altPhone = computed(() => formatPhone(buyer.value?.alternative_phone))

const billing = computed(() => {
  const info = buyer.value?.billing_info
  return info && typeof info === 'object' ? info : null
})

const docLabel = computed(() => {
  const type = billing.value?.doc_type
  const number = billing.value?.doc_number
  if (!type && !number) return null
  return [type, number].filter(Boolean).join(' ')
})

const displayTitle = computed(() => {
  return (
    insights.value?.summary_label ||
    buyer.value?.nickname ||
    fullName.value ||
    `Comprador #${props.order?.buyer_external_id ?? '—'}`
  )
})

async function load(orderId) {
  if (!orderId) return
  loading.value = true
  error.value = null
  payload.value = null
  try {
    const url =
      typeof window !== 'undefined' && window.route
        ? window.route('orders.buyer', orderId)
        : `/orders/${orderId}/buyer`
    payload.value = await fetchSlidePayload(url, { cache: 'no-store' })
  } catch (e) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo cargar el historial del comprador.'
  } finally {
    loading.value = false
  }
}

function retryFetch() {
  load(props.order?.id)
}

watch(
  () => [props.show, props.order?.id],
  ([open, id]) => {
    if (open && id) load(id)
    if (!open) {
      payload.value = null
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
    accessibility-title="Detalle del comprador"
    @close="emit('close')"
    @retry="retryFetch"
  >
    <div v-if="order" class="flex min-h-0 flex-1 flex-col overflow-auto px-4 py-4 sm:px-6">
      <div class="mb-4">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Comprador</p>
        <h2 class="text-lg font-semibold text-slate-900">
          {{ displayTitle }}
        </h2>
        <p v-if="buyer?.nickname && fullName" class="mt-0.5 text-sm text-neutral-600">
          {{ fullName }}
        </p>
        <div v-if="insights?.repeat_customer" class="mt-2">
          <Badge variant="success">Cliente recurrente</Badge>
        </div>
      </div>

      <section
        v-if="insights"
        class="mb-6 grid grid-cols-3 gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3"
      >
        <div>
          <p class="text-[10px] font-medium uppercase tracking-wide text-slate-500">Órdenes</p>
          <p class="mt-1 text-lg font-semibold text-slate-900">{{ insights.orders_count }}</p>
        </div>
        <div>
          <p class="text-[10px] font-medium uppercase tracking-wide text-slate-500">Gastado</p>
          <p class="mt-1 text-lg font-semibold text-slate-900">
            <MoneyText
              :amount="insights.lifetime_spent"
              :currency="insights.currency_code"
            />
          </p>
        </div>
        <div>
          <p class="text-[10px] font-medium uppercase tracking-wide text-slate-500">Ticket prom.</p>
          <p class="mt-1 text-lg font-semibold text-slate-900">
            <MoneyText
              :amount="insights.average_order_value"
              :currency="insights.currency_code"
            />
          </p>
        </div>
      </section>

      <dl v-if="insights" class="mb-6 grid gap-3 sm:grid-cols-2">
        <div>
          <dt class="text-xs text-muted-foreground">Primera compra</dt>
          <dd class="mt-1 text-sm">
            <template v-if="insights.first_ordered_at">
              {{ formatDateTime(insights.first_ordered_at) }}
              <span class="block text-xs text-muted-foreground">
                {{ formatRelativeShort(insights.first_ordered_at) }}
              </span>
            </template>
            <template v-else>—</template>
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Última compra</dt>
          <dd class="mt-1 text-sm">
            <template v-if="insights.last_ordered_at">
              {{ formatDateTime(insights.last_ordered_at) }}
              <span class="block text-xs text-muted-foreground">
                {{ formatRelativeShort(insights.last_ordered_at) }}
              </span>
            </template>
            <template v-else>—</template>
          </dd>
        </div>
      </dl>

      <dl class="grid gap-4 sm:grid-cols-2">
        <div>
          <dt class="text-xs text-muted-foreground">ID en canal</dt>
          <dd class="mt-1 font-mono text-sm">
            {{ buyer?.id || order.buyer_external_id || '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Nickname</dt>
          <dd class="mt-1 text-sm">{{ buyer?.nickname || '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Nombre</dt>
          <dd class="mt-1 text-sm">{{ fullName || '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Email</dt>
          <dd class="mt-1 break-all text-sm">{{ buyer?.email || '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Teléfono</dt>
          <dd class="mt-1 text-sm">
            {{ phone || '—' }}
            <Badge
              v-if="buyer?.phone?.verified"
              variant="success"
              class="ml-2"
            >
              Verificado
            </Badge>
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Tel. alternativo</dt>
          <dd class="mt-1 text-sm">{{ altPhone || '—' }}</dd>
        </div>
      </dl>

      <section class="mt-6 space-y-3">
        <h3 class="text-sm font-semibold text-slate-900">Facturación (orden)</h3>
        <dl class="grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-xs text-muted-foreground">Documento</dt>
            <dd class="mt-1 text-sm">{{ docLabel || '—' }}</dd>
          </div>
          <div v-if="billing?.name || billing?.last_name">
            <dt class="text-xs text-muted-foreground">Nombre fiscal</dt>
            <dd class="mt-1 text-sm">
              {{ [billing?.name, billing?.last_name].filter(Boolean).join(' ') || '—' }}
            </dd>
          </div>
        </dl>
      </section>

      <section class="mt-6 space-y-3">
        <h3 class="text-sm font-semibold text-slate-900">Órdenes recientes</h3>
        <p class="text-xs text-muted-foreground">
          Basado en órdenes sincronizadas en este workspace. No incluye compras de ML que aún no se hayan importado.
        </p>
        <ul v-if="recentOrders.length" class="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200">
          <li v-for="row in recentOrders" :key="row.id">
            <button
              type="button"
              class="flex w-full items-start justify-between gap-3 px-3 py-3 text-left hover:bg-slate-50"
              @click="emit('open-order', row.id)"
            >
              <div>
                <p class="text-sm font-medium text-slate-900">
                  #{{ row.external_order_id ?? row.id }}
                  <Badge v-if="row.is_current" variant="outline" class="ml-2">Actual</Badge>
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                  <template v-if="row.ordered_at">
                    {{ formatDateTime(row.ordered_at) }}
                    · {{ formatRelativeShort(row.ordered_at) }}
                  </template>
                  <template v-else>—</template>
                </p>
              </div>
              <div class="text-right">
                <OrderStatusPill :status="row.status" />
                <p class="mt-1 text-sm font-medium text-slate-900">
                  <MoneyText :amount="row.total_amount" :currency="row.currency_code" />
                </p>
              </div>
            </button>
          </li>
        </ul>
        <p v-else class="text-sm text-neutral-500">Sin órdenes para este comprador.</p>
      </section>
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
</template>
