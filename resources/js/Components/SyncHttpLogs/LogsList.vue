<script setup>
import LogCard from '@/Components/SyncHttpLogs/LogCard.vue'
import LogsTable from '@/Components/SyncHttpLogs/LogsTable.vue'
import { ClipboardList } from 'lucide-vue-next'

defineProps({
  logs: { type: Array, default: () => [] },
  emptyTitle: { type: String, default: 'No hay logs' },
  emptyDescription: { type: String, default: 'Al sincronizar o recibir webhooks aparecerán aquí.' },
  hasActiveFilters: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
  liveNewIds: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['open', 'clear-filters'])
</script>

<template>
  <div>
    <div
      v-if="logs.length === 0"
      class="flex flex-col items-center justify-center px-6 py-16 text-center"
    >
      <ClipboardList class="mb-3 size-8 text-neutral-300" aria-hidden="true" />
      <p class="font-mono text-sm font-medium text-neutral-800">{{ emptyTitle }}</p>
      <p class="mt-1 max-w-sm text-xs text-neutral-500">{{ emptyDescription }}</p>
      <button
        v-if="hasActiveFilters"
        type="button"
        class="mt-4 font-mono text-xs text-sky-700 underline-offset-2 hover:underline"
        @click="emit('clear-filters')"
      >
        Limpiar filtros
      </button>
    </div>

    <template v-else>
      <div class="md:hidden">
        <LogCard
          v-for="log in logs"
          :key="log.id"
          :log="log"
          :is-live-new="!!liveNewIds[log.id]"
          @open="emit('open', $event)"
        />
      </div>

      <LogsTable
        :logs="logs"
        :compact="compact"
        :live-new-ids="liveNewIds"
        @open="emit('open', $event)"
      />
    </template>
  </div>
</template>

<style>
.sync-log-live-new {
  animation: sync-log-live-highlight 2.5s ease-out;
}

@keyframes sync-log-live-highlight {
  0% {
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.45);
    background-color: rgba(16, 185, 129, 0.08);
  }
  100% {
    box-shadow: none;
    background-color: transparent;
  }
}
</style>
