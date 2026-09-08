<script setup>
import Badge from '@/Components/ui/Badge.vue'
import { formatDateTime } from '@/lib/utils'

const props = defineProps({
  timeline: { type: Array, default: () => [] },
  variantId: { type: [Number, String], default: null },
})

const emit = defineEmits(['open-purchase-order', 'open-full-operation', 'open-order'])

function stepLabel(step) {
  return (
    {
      A: 'OC',
      B: 'Recepción',
      C: 'Envío Full',
      'C.1': 'Full',
      D: 'Venta Full',
      E: 'Vuelta',
    }[step] ?? step
  )
}

function stepVariant(step) {
  return (
    {
      A: 'muted',
      B: 'success',
      C: 'warning',
      'C.1': 'default',
      D: 'danger',
      E: 'secondary',
    }[step] ?? 'muted'
  )
}

function onClick(event) {
  const links = event?.links ?? {}
  if (links.purchase_order_id) {
    emit('open-purchase-order', links.purchase_order_id)
    return
  }
  if (links.order_id) {
    emit('open-order', links.order_id)
    return
  }
  if (links.full_operation_id) {
    emit('open-full-operation', links.full_operation_id)
  }
}

function ledgerHref() {
  if (!props.variantId) return route('inventory.ledger.index')
  return route('inventory.ledger.index', { variant_id: props.variantId })
}

function fullHref() {
  if (!props.variantId) return route('inventory.full-operations.index')
  return route('inventory.full-operations.index', { variant_id: props.variantId })
}

function receiptsHref() {
  if (!props.variantId) return route('inventory.receipts.index')
  return route('inventory.receipts.index', { variant_id: props.variantId })
}
</script>

<template>
  <section>
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
      <h3 class="text-sm font-semibold text-slate-900">Jornada A–E</h3>
      <div class="flex flex-wrap gap-2 text-[11px]">
        <a :href="receiptsHref()" class="text-brand hover:underline">Ver ingresos</a>
        <a :href="fullHref()" class="text-brand hover:underline">Ver Full</a>
        <a :href="ledgerHref()" class="text-brand hover:underline">Ver movimientos</a>
      </div>
    </div>

    <ol
      v-if="timeline.length"
      class="divide-y divide-slate-100 rounded-md border border-slate-200"
    >
      <li
        v-for="event in timeline"
        :key="event.id"
        class="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-slate-50"
        @click="onClick(event)"
      >
        <Badge :variant="stepVariant(event.step)" class="mt-0.5 shrink-0">
          {{ stepLabel(event.step) }}
        </Badge>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-medium text-slate-900">{{ event.title }}</p>
          <p class="text-xs text-muted-foreground">{{ event.subtitle }}</p>
        </div>
        <span class="shrink-0 text-[11px] text-muted-foreground">
          {{ formatDateTime(event.occurred_at) }}
        </span>
      </li>
    </ol>
    <p
      v-else
      class="rounded-md border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-muted-foreground"
    >
      Aún no hay jornada para este SKU.
    </p>
  </section>
</template>
