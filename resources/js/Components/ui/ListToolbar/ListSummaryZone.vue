<template>
  <div class="list-toolbar-summary-zone flex min-h-10 w-full min-w-0 items-center">
    <p
      class="list-toolbar-summary min-w-0 truncate"
      :class="dense ? 'shrink px-2 sm:px-3' : 'flex-1 px-3'"
    >
      <slot name="inline">
        <template v-for="(item, idx) in items" :key="item.type + '-' + idx">
          <span v-if="idx > 0" class="list-toolbar-summary-sep" aria-hidden="true"> · </span>
          <template v-if="item.type === 'count' || item.type === 'boxes'">
            <span class="list-toolbar-summary-value">{{ item.value }}</span>
            <span class="list-toolbar-summary-unit">{{ ' ' + item.suffix }}</span>
          </template>
          <template v-else-if="item.type === 'revenue'">
            <span class="list-toolbar-summary-label">{{ item.label }}</span>
            <span class="list-toolbar-summary-value ml-1">{{ item.value }}</span>
          </template>
          <template v-else>
            <span class="list-toolbar-summary-label">{{ item.label }}</span>
            <span v-if="item.value" class="list-toolbar-summary-value ml-1">{{ item.value }}</span>
          </template>
        </template>
      </slot>
    </p>
    <div v-if="expandable && showExpandButton" class="list-toolbar-rail-end">
      <button
        type="button"
        class="list-toolbar-btn-detail shrink-0"
        :class="expanded ? 'list-toolbar-btn-detail-active' : ''"
        :aria-expanded="expanded"
        @click="$emit('toggle-expanded')"
      >
        <span>{{ expanded ? 'Ocultar' : 'Ver detalle' }}</span>
        <svg
          class="h-3.5 w-3.5 shrink-0 transition-transform duration-300 ease-out"
          :class="{ 'rotate-180': expanded }"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  items: { type: Array, default: () => [] },
  expanded: { type: Boolean, default: false },
  expandable: { type: Boolean, default: false },
  /** false = el padre renderiza el botón en otro rail (modo 1 fila) */
  showExpandButton: { type: Boolean, default: true },
  /** En 1 fila: métricas no consumen todo el ancho */
  dense: { type: Boolean, default: false },
})

defineEmits(['toggle-expanded'])
</script>
