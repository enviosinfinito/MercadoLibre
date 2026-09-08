<script setup>
/**
 * Compat: el slide canónico es ProductDetailSlideOver (3 tabs).
 * Desde Productos abre en tab "product".
 */
import ProductDetailSlideOver from '@/Components/Products/ProductDetailSlideOver.vue'

defineProps({
  show: { type: Boolean, default: false },
  productId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close', 'saved'])
</script>

<template>
  <ProductDetailSlideOver
    :show="show"
    :product-id="productId != null ? Number(productId) : null"
    initial-tab="product"
    @close="emit('close')"
    @saved="emit('saved', $event)"
  />
</template>
