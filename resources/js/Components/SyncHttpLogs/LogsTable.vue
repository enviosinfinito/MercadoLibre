<script setup>
import {
  directionBadgeClass,
  directionLabel,
  getTypeTag,
  getTypeTagClass,
  latencyMeta,
  shortUrl,
  typeLabel,
} from '@/composables/useSyncHttpLogs'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import { connectionSurfaceStyle } from '@/lib/connectionColor'
import { formatFriendlyDateTime } from '@/lib/utils'

defineProps({
  logs: { type: Array, default: () => [] },
  compact: { type: Boolean, default: false },
  liveNewIds: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['open'])
</script>

<template>
  <div class="hidden overflow-x-auto md:block">
    <table class="w-full min-w-[720px] border-collapse font-mono text-[11px]">
      <thead class="sticky top-0 z-[1] bg-neutral-50/95 backdrop-blur-sm">
        <tr class="border-b border-neutral-200 text-left text-[10px] tracking-wide text-neutral-500 uppercase">
          <th class="px-3 py-2 font-medium">Id</th>
          <th class="px-3 py-2 font-medium">Fecha</th>
          <th class="px-3 py-2 font-medium">Cuenta</th>
          <th class="px-3 py-2 font-medium">Tipo</th>
          <th v-if="!compact" class="px-3 py-2 font-medium">Dir</th>
          <th v-if="!compact" class="px-3 py-2 font-medium">HTTP</th>
          <th v-if="!compact" class="px-3 py-2 font-medium">Corr</th>
          <th class="px-3 py-2 font-medium">Url</th>
          <th class="px-3 py-2 font-medium">Lat</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="log in logs"
          :key="log.id"
          class="conn-row cursor-pointer border-b border-neutral-100/80 transition"
          :class="{ 'sync-log-live-new': !!liveNewIds[log.id] }"
          :style="connectionSurfaceStyle(log.connection?.color)"
          @click="emit('open', log.id)"
        >
          <td class="px-3 py-2 tabular-nums text-neutral-800">{{ log.id }}</td>
          <td
            class="whitespace-nowrap px-3 py-2 text-neutral-500"
            :title="log.created_at ?? undefined"
          >
            {{ formatFriendlyDateTime(log.created_at) }}
          </td>
          <td class="max-w-[10rem] px-3 py-2">
            <ConnectionChip
              v-if="log.connection"
              :connection="log.connection"
            />
            <span v-else class="text-neutral-400">—</span>
          </td>
          <td class="px-3 py-2">
            <span :class="getTypeTagClass(log)" class="font-semibold">{{ getTypeTag(log) }}</span>
            <div class="mt-0.5 max-w-[8rem] truncate text-[10px] text-neutral-400" :title="typeLabel(log)">
              {{ typeLabel(log) }}
            </div>
          </td>
          <td v-if="!compact" class="px-3 py-2">
            <span
              class="inline-flex h-4 items-center rounded px-1 text-[10px] font-semibold"
              :class="directionBadgeClass(log.direction)"
            >
              {{ directionLabel(log.direction) }}
            </span>
          </td>
          <td v-if="!compact" class="px-3 py-2 tabular-nums text-neutral-600">
            {{ log.response_status ?? '—' }}
          </td>
          <td
            v-if="!compact"
            class="max-w-[7rem] truncate px-3 py-2 text-neutral-400"
            :title="log.correlation_id || undefined"
          >
            {{ log.correlation_id || '—' }}
          </td>
          <td class="max-w-[16rem] px-3 py-2">
            <div class="truncate text-neutral-700" :title="log.url || undefined">
              {{ shortUrl(log.url) }}
            </div>
            <div v-if="log.method" class="text-[10px] text-neutral-400">{{ log.method }}</div>
          </td>
          <td class="whitespace-nowrap px-3 py-2 tabular-nums text-neutral-500">
            <span
              class="mr-1 inline-block size-1.5 rounded-full"
              :class="latencyMeta(log.latency_ms).dotClass"
            />
            {{ log.latency_ms != null ? `${log.latency_ms}ms` : '—' }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
