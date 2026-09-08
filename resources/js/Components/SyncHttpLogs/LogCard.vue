<script setup>
import {
  directionBadgeClass,
  directionLabel,
  getTypeTag,
  getTypeTagClass,
  latencyMeta,
  shortUrl,
} from '@/composables/useSyncHttpLogs'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import { connectionSurfaceStyle } from '@/lib/connectionColor'
import { formatFriendlyDateTime } from '@/lib/utils'

const props = defineProps({
  log: { type: Object, required: true },
  isLiveNew: { type: Boolean, default: false },
})

const emit = defineEmits(['open'])

const latency = () => latencyMeta(props.log.latency_ms)
</script>

<template>
  <button
    type="button"
    class="conn-row flex w-full flex-col gap-1 border-b border-neutral-100 px-3 py-2.5 text-left font-mono text-xs transition"
    :class="{ 'sync-log-live-new': isLiveNew }"
    :style="connectionSurfaceStyle(log.connection?.color)"
    @click="emit('open', log.id)"
  >
    <div class="flex flex-wrap items-center gap-1.5">
      <span class="tabular-nums text-neutral-800">{{ log.id }}</span>
      <span
        v-if="log.correlation_id"
        class="max-w-[7rem] truncate text-[10px] text-neutral-400"
        :title="log.correlation_id"
      >
        {{ log.correlation_id }}
      </span>
      <span :class="getTypeTagClass(log)" class="font-semibold">{{ getTypeTag(log) }}</span>
      <span
        class="inline-flex h-4 items-center rounded px-1 text-[10px] font-semibold"
        :class="directionBadgeClass(log.direction)"
      >
        {{ directionLabel(log.direction) }}
      </span>
      <span class="ml-auto inline-flex items-center gap-1 tabular-nums text-neutral-500">
        <span class="inline-block size-1.5 rounded-full" :class="latency().dotClass" />
        {{ log.latency_ms != null ? `${log.latency_ms}ms` : '—' }}
      </span>
    </div>
    <div class="flex flex-wrap items-center gap-1.5 text-[10px] text-neutral-500">
      <span>{{ formatFriendlyDateTime(log.created_at) }}</span>
      <ConnectionChip
        v-if="log.connection"
        :connection="log.connection"
      />
      <template v-if="log.response_status != null">
        <span class="text-neutral-300">·</span>
        <span>HTTP {{ log.response_status }}</span>
      </template>
    </div>
    <div class="truncate text-[10px] text-neutral-600" :title="log.url || undefined">
      <span v-if="log.method" class="text-neutral-400">{{ log.method }} </span>{{ shortUrl(log.url) }}
    </div>
  </button>
</template>
