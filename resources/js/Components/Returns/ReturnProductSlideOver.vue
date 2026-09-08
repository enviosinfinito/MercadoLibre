<script setup lang="ts">
/**
 * Compat: usa ProductDetailSlideOver con tab inicial "returns".
 */
import { computed } from 'vue'
import ProductDetailSlideOver from '@/Components/Products/ProductDetailSlideOver.vue'

const props = withDefaults(
  defineProps<{
    show: boolean
    productKey: string | null
    period?: string
    connectionIds?: number[]
  }>(),
  {
    period: 'last_30_days',
    connectionIds: () => [],
  },
)

const emit = defineEmits<{
  close: []
}>()

const productId = computed(() => {
  if (!props.productKey || String(props.productKey).startsWith('ml:')) return null
  const n = Number(props.productKey)
  return Number.isFinite(n) ? n : null
})

const mlItemId = computed(() => {
  if (!props.productKey || !String(props.productKey).startsWith('ml:')) return null
  return String(props.productKey).slice(3)
})
</script>

<template>
  <ProductDetailSlideOver
    :show="show"
    :product-id="productId"
    :ml-item-id="mlItemId"
    :returns-key="productKey"
    :period="period"
    :connection-ids="connectionIds"
    initial-tab="returns"
    @close="emit('close')"
  />
</template>
