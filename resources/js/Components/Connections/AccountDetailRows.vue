<script setup>
defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
})
</script>

<template>
  <dl class="divide-y divide-slate-100">
    <div
      v-for="(row, index) in rows"
      :key="`${row.label}-${index}`"
      class="grid grid-cols-[7.5rem_1fr] gap-2 py-1.5 text-[11px] first:pt-0 last:pb-0"
    >
      <dt class="text-slate-400">{{ row.label }}</dt>
      <dd class="min-w-0 break-words font-medium text-slate-800">
        <template v-if="row.value != null && row.value !== ''">{{ row.value }}</template>
        <span v-else class="font-normal text-slate-300">—</span>
      </dd>
    </div>
  </dl>
</template>
