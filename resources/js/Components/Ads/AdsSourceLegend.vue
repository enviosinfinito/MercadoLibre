<script setup lang="ts">
withDefaults(
  defineProps<{
    compact?: boolean
  }>(),
  { compact: false },
)
</script>

<template>
  <div
    class="flex flex-wrap gap-2 text-[11px] text-slate-600"
    :class="compact ? '' : 'rounded-md border border-slate-200 bg-white px-3 py-2'"
  >
    <span class="inline-flex items-center gap-1">
      <span class="rounded bg-sky-100 px-1 py-0.5 text-[9px] font-semibold uppercase text-sky-800">Mercado Ads</span>
      lo que reporta la plataforma
    </span>
    <span class="text-slate-300">·</span>
    <span class="inline-flex items-center gap-1">
      <span class="rounded bg-slate-200 px-1 py-0.5 text-[9px] font-semibold uppercase text-slate-800">Tus ventas</span>
      órdenes de tu cuenta
    </span>
    <span class="text-slate-300">·</span>
    <span class="inline-flex items-center gap-1">
      <span class="rounded bg-teal-100 px-1 py-0.5 text-[9px] font-semibold uppercase text-teal-800">Nuestro cálculo</span>
      cruzamos ads con tu ganancia
    </span>
  </div>
</template>
