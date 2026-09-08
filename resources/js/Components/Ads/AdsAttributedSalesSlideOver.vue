<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import { connectionSurfaceStyle } from '@/lib/connectionColor'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { useDetailSlide } from '@/composables/useDetailSlide'

const props = defineProps<{
  show: boolean
  mlItemId: string | null
  connectionId?: number | null
  days?: number
}>()

const emit = defineEmits<{ close: [] }>()

const currency = 'MXN'
const syncing = ref(false)
const syncError = ref<string | null>(null)
const pollTimer = ref<ReturnType<typeof setInterval> | null>(null)

const { show, loading, error, data, open, close, retry } = useDetailSlide({
  fetchUrl: (id: string) => {
    const params: Record<string, unknown> = {
      mlItemId: id,
      days: props.days || 14,
    }
    if (props.connectionId) params.connection_id = props.connectionId
    return route('ads.assistant.items.sales', params)
  },
  cache: 'no-store',
})

watch(
  () => [props.show, props.mlItemId, props.connectionId, props.days] as const,
  ([isOpen, id]) => {
    if (isOpen && id) void open(id)
    if (!isOpen) {
      stopPoll()
      close()
    }
  },
  { immediate: true },
)

const sales = computed(() => (Array.isArray(data.value?.sales) ? data.value.sales : []) as Array<Record<string, any>>)
const ml = computed(() => data.value?.ml_attribution || {})
const local = computed(() => data.value?.local_sales || {})
const allocation = computed(() => data.value?.allocation || {})
const coverage = computed(() => data.value?.coverage || {})
const sync = computed(() => data.value?.sync || null)

const coveragePct = computed(() => {
  const r = Number(coverage.value?.ratio)
  if (!Number.isFinite(r)) return null
  return Math.round(r * 100)
})

function formatPct(v: number | null | undefined, digits = 0) {
  if (v == null || !Number.isFinite(Number(v))) return '—'
  return `${(Number(v) * 100).toFixed(digits)}%`
}

function onClose() {
  stopPoll()
  close()
  emit('close')
}

function formatDate(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('es-MX', {
      dateStyle: 'short',
      timeStyle: 'short',
    })
  } catch {
    return iso
  }
}

function stopPoll() {
  if (pollTimer.value) {
    clearInterval(pollTimer.value)
    pollTimer.value = null
  }
}

async function triggerSync() {
  if (!props.mlItemId || !props.connectionId) {
    syncError.value = 'Falta connection_id para sincronizar órdenes.'
    return
  }
  syncing.value = true
  syncError.value = null
  try {
    const res = await fetch(route('ads.assistant.items.sync-orders', { mlItemId: props.mlItemId }), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN':
          (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
      },
      body: JSON.stringify({
        connection_id: props.connectionId,
        days: props.days || 14,
      }),
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error(body.message || `Error ${res.status}`)
    }
    await open(props.mlItemId)
    stopPoll()
    pollTimer.value = setInterval(() => {
      if (props.mlItemId) void open(props.mlItemId)
    }, 4000)
  } catch (e: any) {
    syncError.value = e?.message || 'No se pudo encolar el sync'
  } finally {
    syncing.value = false
  }
}

watch(
  () => sync.value?.status,
  (status) => {
    if (status === 'completed' || status === 'failed') {
      stopPoll()
      if (props.mlItemId) void open(props.mlItemId)
    }
  },
)
</script>

<template>
  <SlideOverShell
    :show="show && props.show"
    title="Ventas acreditadas"
    :loading="loading"
    :error="error"
    stackable
    accessibility-title="Ventas acreditadas del ítem"
    @close="onClose"
    @retry="retry"
  >
    <div v-if="data" class="space-y-4 overflow-y-auto p-4">
      <p class="text-xs text-slate-500">
        {{ data.ml_item_id }} · {{ data.period?.start }} → {{ data.period?.end }}
      </p>

      <ol class="space-y-1.5 rounded-md border border-slate-200 bg-white px-3 py-2 text-[11px] text-slate-600">
        <li><span class="font-semibold text-slate-800">1.</span> Lo que dice Mercado Ads</li>
        <li><span class="font-semibold text-slate-800">2.</span> Órdenes que ya tenés</li>
        <li><span class="font-semibold text-slate-800">3.</span> Cómo repartimos el gasto (sin inventar plata)</li>
      </ol>

      <div
        v-if="coverage.needs_sync || coveragePct != null || coverage.kind === 'waste'"
        class="rounded-md border px-3 py-2 text-xs"
        :class="
          coverage.kind === 'short' || coverage.needs_sync
            ? 'border-amber-200 bg-amber-50 text-amber-950'
            : coverage.kind === 'waste'
              ? 'border-rose-200 bg-rose-50 text-rose-950'
              : coverage.kind === 'organic_heavy'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-950'
                : 'border-slate-200 bg-slate-50 text-slate-700'
        "
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p class="font-semibold">
              <template v-if="coverage.kind === 'organic_heavy'">Más ventas locales que ads</template>
              <template v-else-if="coverage.kind === 'waste'">Gasto sin ventas atribuidas (waste)</template>
              <template v-else-if="coverage.kind === 'short'">Faltan órdenes sync</template>
              <template v-else>Cobertura local</template>
              <span v-if="coveragePct != null" class="tabular-nums"> {{ coveragePct }}%</span>
              <span v-if="coveragePct != null" class="font-normal opacity-80">
                (rev {{ formatPct(coverage.revenue_ratio) }} · uds {{ formatPct(coverage.units_ratio) }})
              </span>
            </p>
            <p class="mt-0.5 text-[11px] opacity-90">
              {{ coverage.note || (coverage.needs_sync
                ? 'Nos faltan órdenes sincronizadas vs lo que ML atribuye.'
                : 'Tus órdenes cubren lo que ML atribuye a ads.') }}
            </p>
          </div>
          <button
            v-if="coverage.kind === 'short' || coverage.needs_sync || sync?.status === 'running'"
            type="button"
            class="shrink-0 rounded-md bg-teal-700 px-2.5 py-1.5 text-[11px] font-medium text-white disabled:opacity-50"
            :disabled="syncing || sync?.status === 'running' || !connectionId"
            @click="triggerSync"
          >
            <template v-if="sync?.status === 'running' || syncing">Sincronizando…</template>
            <template v-else>Traer órdenes faltantes</template>
          </button>
        </div>
        <p v-if="syncError" class="mt-1 text-rose-700">{{ syncError }}</p>
        <p v-if="sync?.status === 'failed'" class="mt-1 text-rose-700">
          Sync falló: {{ sync.error || 'error desconocido' }}
        </p>
        <p v-else-if="sync?.status === 'completed'" class="mt-1 text-emerald-800">
          Sync listo · {{ sync.items ?? 0 }} órdenes despachadas
          <span v-if="sync.truncated" class="text-amber-800">
            · truncado por tope de seguridad ({{ sync.truncated_reason || 'cap' }})
          </span>
          · atribución recalculada
        </p>
        <p v-else-if="sync?.status === 'running'" class="mt-1 text-amber-900">
          En curso: {{ sync.pages ?? '…' }} páginas · {{ sync.items ?? 0 }} órdenes.
          Sigue por tandas automáticamente (más de 2.500 si hace falta). Puede tardar varios minutos.
        </p>
      </div>

      <div class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-950">
        <div class="flex items-center gap-2">
          <span class="rounded-full bg-sky-200/80 px-1.5 py-0.5 text-[10px] font-semibold uppercase">Mercado Ads</span>
          <p class="font-semibold">1. Lo que reporta Mercado Ads</p>
        </div>
        <p class="mt-1">
          Ventas atribuidas
          <MoneyText :amount="ml.revenue" :currency="currency" />
          · {{ ml.units ?? 0 }} uds · gasto
          <MoneyText :amount="ml.cost" :currency="currency" />
          <span v-if="ml.acos != null" class="text-sky-800/80">
            · {{ formatPct(ml.acos, 1) }} del ingreso en ads
          </span>
        </p>
        <p class="mt-1 text-sky-800/80">{{ ml.note }}</p>
      </div>

      <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
        <div class="flex items-center gap-2">
          <span class="rounded-full bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase">Tus ventas</span>
          <p class="font-semibold text-slate-900">2. Órdenes locales del ítem</p>
        </div>
        <p class="mt-1">
          {{ local.orders_count ?? 0 }} órdenes · {{ local.units ?? 0 }} uds · ventas
          <MoneyText :amount="local.revenue" :currency="currency" />
          · ads asignado
          <MoneyText :amount="local.ads_allocated" :currency="currency" />
        </p>
        <p class="mt-1 text-slate-500">{{ local.note }}</p>
      </div>

      <div class="rounded-md border border-teal-200 bg-teal-50/60 px-3 py-2 text-xs text-teal-950">
        <div class="flex items-center gap-2">
          <span class="rounded-full bg-teal-200/80 px-1.5 py-0.5 text-[10px] font-semibold uppercase">Nuestro</span>
          <p class="font-semibold">3. Cómo repartimos el gasto</p>
        </div>
        <p class="mt-1">
          En órdenes
          <MoneyText :amount="allocation.ads_allocated" :currency="currency" />
          · ads sin órdenes todavía
          <MoneyText :amount="allocation.ads_residual" :currency="currency" />
          · gasto ML
          <MoneyText :amount="allocation.ml_cost" :currency="currency" />
        </p>
        <p class="mt-1 text-teal-900/80">{{ allocation.note }}</p>
      </div>

      <div
        v-if="!sales.length"
        class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500"
      >
        No hay órdenes locales de este ítem en la ventana. Puede haber atribución ML sin que la orden esté
        sincronizada aún.
      </div>

      <div v-else class="overflow-hidden rounded-md border border-slate-200">
        <table class="min-w-full text-xs">
          <thead class="bg-slate-50 text-left text-slate-500">
            <tr>
              <th class="px-2.5 py-1.5 font-medium">Orden</th>
              <th class="px-2.5 py-1.5 font-medium">Canal</th>
              <th class="px-2.5 py-1.5 font-medium">Fecha</th>
              <th class="px-2.5 py-1.5 text-right font-medium">Uds</th>
              <th class="px-2.5 py-1.5 text-right font-medium">Revenue</th>
              <th class="px-2.5 py-1.5 text-right font-medium">Ads asignado</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="s in sales"
              :key="s.order_line_id"
              class="conn-row border-t border-slate-100"
              :style="connectionSurfaceStyle(s.connection?.color)"
            >
              <td class="px-2.5 py-1.5">
                <Link
                  :href="s.order_url"
                  class="font-medium text-teal-700 hover:underline"
                >
                  #{{ s.external_order_id || s.order_id }}
                </Link>
                <p class="text-[10px] text-slate-400">{{ s.status }}</p>
              </td>
              <td class="px-2.5 py-1.5">
                <ConnectionChip
                  v-if="s.connection"
                  :connection="s.connection"
                />
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="px-2.5 py-1.5 text-slate-600">{{ formatDate(s.ordered_at) }}</td>
              <td class="px-2.5 py-1.5 text-right tabular-nums">{{ s.quantity }}</td>
              <td class="px-2.5 py-1.5 text-right">
                <MoneyText :amount="s.line_revenue" :currency="s.currency || currency" />
              </td>
              <td class="px-2.5 py-1.5 text-right">
                <MoneyText
                  :amount="s.ads_cost_allocated"
                  :currency="s.currency || currency"
                  :class="s.has_ads_allocation ? '' : 'text-slate-400'"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <template #footer>
      <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
        <template #end>
          <ActionGroup>
            <ActionButton variant="secondary" @click="onClose">Cerrar</ActionButton>
          </ActionGroup>
        </template>
      </ActionBar>
    </template>
  </SlideOverShell>
</template>
