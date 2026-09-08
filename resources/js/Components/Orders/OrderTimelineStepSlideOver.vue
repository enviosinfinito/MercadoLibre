<script setup>
import { computed } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { formatDateTime, formatRelativeShort } from '@/lib/utils'

const props = defineProps({
  show: { type: Boolean, default: false },
  stepKey: { type: String, default: null },
  order: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const title = computed(() => {
  if (props.stepKey === 'paid') return 'Detalle de pago'
  return 'Detalle de creación'
})

const providerStatus = computed(() => props.order?.meta?.provider_status ?? null)

const buyerLabel = computed(() => {
  const id = props.order?.buyer_external_id
  if (id == null || id === '') return '—'
  return `Comprador #${id}`
})
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    mobile-full-bleed
    fill-height
    accessibility-title="Detalle del paso"
    @close="emit('close')"
  >
    <div v-if="order" class="flex min-h-0 flex-1 flex-col overflow-auto px-4 py-4 sm:px-6">
      <div class="mb-4">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Orden</p>
        <h2 class="text-lg font-semibold text-slate-900">
          {{ title }} · #{{ order.external_order_id ?? order.id }}
        </h2>
      </div>

      <dl class="grid gap-4 sm:grid-cols-2">
        <div>
          <dt class="text-xs text-muted-foreground">Estado actual</dt>
          <dd class="mt-1">
            <Badge variant="secondary" class="capitalize">{{ order.status }}</Badge>
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Status en canal</dt>
          <dd class="mt-1 text-sm capitalize">{{ providerStatus || '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Creada</dt>
          <dd class="mt-1 text-sm">
            <template v-if="order.ordered_at">
              {{ formatDateTime(order.ordered_at) }}
              <span class="block text-xs text-muted-foreground">
                {{ formatRelativeShort(order.ordered_at) }}
              </span>
            </template>
            <template v-else>—</template>
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Pagada</dt>
          <dd class="mt-1 text-sm">
            <template v-if="order.paid_at">
              {{ formatDateTime(order.paid_at) }}
              <span class="block text-xs text-muted-foreground">
                {{ formatRelativeShort(order.paid_at) }}
              </span>
            </template>
            <template v-else>—</template>
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Total</dt>
          <dd class="mt-1">
            <MoneyText :amount="order.total_amount" :currency="order.currency_code" />
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted-foreground">Comprador</dt>
          <dd class="mt-1 text-sm">{{ buyerLabel }}</dd>
        </div>
        <div v-if="order.meta?.shipping?.id">
          <dt class="text-xs text-muted-foreground">Shipping ID (ML)</dt>
          <dd class="mt-1 font-mono text-sm">{{ order.meta.shipping.id }}</dd>
        </div>
      </dl>
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
