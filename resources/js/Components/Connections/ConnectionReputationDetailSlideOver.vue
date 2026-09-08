<script setup>
import { computed } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import AccountDetailRows from '@/Components/Connections/AccountDetailRows.vue'
import ConnectionReputationTimeline from '@/Components/Connections/ConnectionReputationTimeline.vue'
import Badge from '@/Components/ui/Badge.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { toTitleCase } from '@/lib/formatDisplayText'
import {
  bandLabel,
  formatDateTime,
  formatPct,
  reputationLevelLabel,
} from '@/lib/accountProfileDisplay'

const props = defineProps({
  show: { type: Boolean, default: false },
  connection: { type: Object, default: null },
  reputationTimeline: { type: Object, default: () => ({ points: [], milestones: [] }) },
})

const emit = defineEmits(['close'])

const meta = computed(() => props.connection?.reputation_meta ?? null)
const evaluation = computed(() => meta.value?.evaluation ?? null)
const metrics = computed(() => meta.value?.metrics ?? {})
const transactions = computed(() => meta.value?.transactions ?? {})
const buyer = computed(() => props.connection?.account_profile?.buyer_reputation ?? null)

const title = computed(
  () => props.connection?.display_name || props.connection?.external_user_id || 'Reputación',
)

const levelLabel = computed(() => reputationLevelLabel(props.connection?.reputation_level))
const medalLabel = computed(() => {
  const status = props.connection?.power_seller_status
  return status ? `Líder ${toTitleCase(status)}` : null
})

const metricLabel = {
  claims: 'Reclamos',
  cancellations: 'Cancelaciones',
  delayed_handling_time: 'Despacho tardío',
}

const drivers = computed(() => evaluation.value?.drivers ?? [])

const protectionRows = computed(() => {
  const e = evaluation.value ?? {}
  if (!e.protected) return [{ label: 'Protección', value: 'No' }]
  return [
    { label: 'Protección', value: 'Sí' },
    { label: 'Nivel real', value: e.real_level },
    { label: 'Fin protección', value: formatDateTime(e.protection_end_date) },
  ]
})

const transactionRows = computed(() => {
  const t = transactions.value ?? {}
  const r = t.ratings ?? {}
  return [
    { label: 'Período', value: t.period },
    { label: 'Total', value: t.total != null ? String(t.total) : null },
    { label: 'Completadas', value: t.completed != null ? String(t.completed) : null },
    { label: 'Canceladas', value: t.canceled != null ? String(t.canceled) : null },
    { label: 'Positivas', value: formatPct(r.positive) },
    { label: 'Neutrales', value: formatPct(r.neutral) },
    { label: 'Negativas', value: formatPct(r.negative) },
    {
      label: 'Ventas período',
      value: metrics.value?.sales?.completed != null
        ? `${metrics.value.sales.completed} (${metrics.value.sales.period || '—'})`
        : null,
    },
  ]
})

const buyerRows = computed(() => {
  const b = buyer.value
  if (!b) return []
  const tx = b.transactions ?? {}
  return [
    { label: 'Canceladas (buyer)', value: b.canceled_transactions != null ? String(b.canceled_transactions) : null },
    { label: 'Período', value: tx.period },
    { label: 'Total', value: tx.total != null ? String(tx.total) : null },
    { label: 'Completadas', value: tx.completed != null ? String(tx.completed) : null },
    {
      label: 'Tags buyer',
      value: Array.isArray(b.tags) && b.tags.length ? b.tags.join(', ') : null,
    },
  ]
})

const causalText = computed(() => {
  const limiting = evaluation.value?.limiting_drivers ?? []
  if (!limiting.length) return null
  const names = limiting.map((d) => metricLabel[d.metric] || d.metric).join(', ')
  return `Tu color lo limita ${names} (banda ${bandLabel(evaluation.value?.worst_band)}).`
})

const syncedAt = computed(() => formatDateTime(props.connection?.reputation_synced_at))
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    compact-header
    fill-height
    mobile-full-bleed
    stackable
    :accessibility-title="`Reputación · ${title}`"
    @close="emit('close')"
  >
    <template #header>
      <p class="truncate text-[13px] font-semibold tracking-tight text-slate-900">
        Reputación · {{ title }}
      </p>
    </template>

    <div class="space-y-4 px-4 py-3 sm:px-5">
      <div class="flex flex-wrap items-center gap-1.5">
        <Badge v-if="medalLabel" variant="secondary" class="h-5 rounded-full px-1.5 text-[10px]">
          {{ medalLabel }}
        </Badge>
        <Badge
          v-if="levelLabel"
          :variant="connection?.reputation_level?.includes('green') ? 'success' : connection?.reputation_level?.includes('red') ? 'danger' : 'warning'"
          class="h-5 rounded-full px-1.5 text-[10px]"
        >
          {{ levelLabel }}
        </Badge>
        <span v-if="syncedAt" class="text-[10px] text-slate-400">Sync {{ syncedAt }}</span>
      </div>

      <p v-if="causalText" class="text-[11px] leading-relaxed text-slate-600">{{ causalText }}</p>

      <section class="space-y-1.5">
        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Protección</h3>
        <AccountDetailRows :rows="protectionRows" />
      </section>

      <section class="space-y-2">
        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Métricas</h3>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
          <div
            v-for="driver in drivers"
            :key="driver.metric"
            class="rounded-md border border-slate-200/80 bg-slate-50/60 px-2.5 py-2"
          >
            <p class="text-[10px] text-slate-400">{{ metricLabel[driver.metric] || driver.metric }}</p>
            <p class="text-[14px] font-semibold tabular-nums text-slate-900">
              {{ formatPct(driver.rate) || '—' }}
            </p>
            <p class="text-[10px] text-slate-500">
              {{ bandLabel(driver.band) }}
              <span v-if="driver.value != null"> · {{ driver.value }}</span>
              <span v-if="driver.threshold != null"> · umbral {{ formatPct(driver.threshold) }}</span>
            </p>
            <p v-if="metrics[driver.metric]?.period" class="text-[10px] text-slate-400">
              {{ metrics[driver.metric].period }}
            </p>
          </div>
        </div>
      </section>

      <section class="space-y-1.5">
        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Transacciones</h3>
        <AccountDetailRows :rows="transactionRows" />
      </section>

      <section class="space-y-1.5">
        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Evolución</h3>
        <ConnectionReputationTimeline :timeline="reputationTimeline" />
      </section>

      <section v-if="buyerRows.length" class="space-y-1.5">
        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
          Reputación como comprador
        </h3>
        <AccountDetailRows :rows="buyerRows" />
      </section>
    </div>

    <template #footer>
      <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
        <ActionGroup align="end">
          <ActionButton variant="outline" @click="emit('close')">Cerrar</ActionButton>
        </ActionGroup>
      </ActionBar>
    </template>
  </SlideOverShell>
</template>
