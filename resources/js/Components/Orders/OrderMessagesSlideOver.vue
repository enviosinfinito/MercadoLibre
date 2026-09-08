<script setup>
import OrderMessagesPanel from '@/Components/Orders/OrderMessagesPanel.vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import { ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS } from '@/lib/slideOverLayout'
import { computed } from 'vue'

const props = defineProps({
  show: { type: Boolean, default: false },
  orderId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close'])

const active = computed(() => Boolean(props.show && props.orderId))
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    compact-header
    fill-height
    mobile-full-bleed
    :max-width="ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS"
    accessibility-title="Mensajes de la orden"
    @close="emit('close')"
  >
    <template #header>
      <div class="min-w-0">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Mensajes</p>
        <h2 class="truncate text-base font-semibold text-slate-900">
          Orden #{{ orderId ?? '—' }}
        </h2>
      </div>
    </template>

    <div class="flex min-h-0 flex-1 flex-col overflow-hidden px-4 py-4 sm:px-6">
      <OrderMessagesPanel
        v-if="orderId"
        :order-id="orderId"
        :active="active"
      />
    </div>
  </SlideOverShell>
</template>
