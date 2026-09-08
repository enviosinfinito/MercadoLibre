<script setup>
import { computed, ref, watch } from 'vue'
import StackedData from '@/Components/App/StackedData.vue'
import ConnectionHealth from '@/Components/Domain/ConnectionHealth.vue'
import SyncConfigurator from '@/Components/Domain/SyncConfigurator.vue'
import ConnectionColorPicker from '@/Components/Connections/ConnectionColorPicker.vue'
import Badge from '@/Components/ui/Badge.vue'
import { toTitleCase } from '@/lib/formatDisplayText'
import { resolveConnectionColor } from '@/lib/connectionColor'
import {
  bandLabel,
  formatPct,
  maskEmail,
  reputationLevelLabel,
} from '@/lib/accountProfileDisplay'

const props = defineProps({
  connection: { type: Object, required: true },
  catalog: { type: Array, default: () => [] },
  profiles: { type: Array, default: () => [] },
  platforms: { type: Array, default: () => [] },
  reputationTimeline: { type: Object, default: () => ({ points: [], milestones: [] }) },
  purchaseExperienceSummary: {
    type: Object,
    default: () => ({ enabled: false, totals_by_color: {}, without_data: 0, top_problems: [] }),
  },
  compact: { type: Boolean, default: true },
})

const emit = defineEmits(['open-account-section'])

const platformName = computed(() => {
  const match = (props.platforms ?? []).find((p) => p.id === props.connection.provider)
  return match?.name ?? props.connection.provider
})

const accountStacked = computed(() => {
  const c = props.connection
  const displayName = String(c.display_name ?? '').trim()
  const externalId = String(c.external_user_id ?? '').trim()
  const siteId = String(c.site_id ?? '').trim()

  if (displayName) {
    const secondaryParts = [externalId, siteId].filter(Boolean)
    return {
      primary: displayName,
      secondary: secondaryParts.length ? secondaryParts.join(' · ') : null,
      primaryKind: 'name',
      secondaryKind: 'code',
    }
  }

  if (externalId) {
    return {
      primary: externalId,
      secondary: siteId || null,
      primaryKind: 'code',
      secondaryKind: 'code',
    }
  }

  return {
    primary: 'Sin cuenta externa',
    secondary: null,
    primaryKind: 'label',
    secondaryKind: null,
  }
})

const accountTitle = computed(() => accountStacked.value.primary)

const initials = computed(() => {
  const label = accountTitle.value
  const parts = label.replace(/[^a-zA-Z0-9]+/g, ' ').trim().split(/\s+/)
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase()
  }
  return label.slice(0, 2).toUpperCase()
})

const reputationBadgeVariant = computed(() => {
  const level = props.connection.reputation_level
  if (!level) return 'muted'
  if (level.includes('green')) return 'success'
  if (level.includes('yellow') || level.includes('orange')) return 'warning'
  if (level.includes('red')) return 'danger'
  return 'muted'
})

const reputationLabel = computed(() => reputationLevelLabel(props.connection.reputation_level))

const powerSellerLabel = computed(() => {
  const status = props.connection.power_seller_status
  if (!status) return null
  return `Líder ${toTitleCase(status)}`
})

const profile = computed(() => props.connection.account_profile ?? null)
const statusBlock = computed(() => profile.value?.status ?? null)
const meta = computed(() => props.connection.reputation_meta ?? null)
const evaluation = computed(() => meta.value?.evaluation ?? null)
const drivers = computed(() => evaluation.value?.drivers ?? [])
const peSummary = computed(() => props.purchaseExperienceSummary ?? {})

const profileSummary = computed(() => {
  const p = profile.value
  if (!p) return 'Sin perfil sincronizado. Corre enrich de reputación.'
  const parts = []
  if (p.user_type) parts.push(p.user_type)
  if (p.seller_experience) parts.push(p.seller_experience)
  const email = maskEmail(p.email)
  if (email) parts.push(email)
  if (Array.isArray(p.tags) && p.tags.length) parts.push(`${p.tags.length} tags`)
  return parts.length ? parts.join(' · ') : 'Perfil disponible'
})

const statusSummary = computed(() => {
  const s = statusBlock.value
  if (!s) return 'Sin estado sincronizado'
  const bits = []
  if (s.site_status) bits.push(s.site_status)
  bits.push(`Vender: ${s.sell?.allow === false ? 'no' : 'sí'}`)
  bits.push(`Publicar: ${s.list?.allow === false ? 'no' : 'sí'}`)
  bits.push(`Comprar: ${s.buy?.allow === false ? 'no' : 'sí'}`)
  return bits.join(' · ')
})

const reputationSummary = computed(() => {
  const parts = []
  if (reputationLabel.value) parts.push(reputationLabel.value)
  if (powerSellerLabel.value) parts.push(powerSellerLabel.value)
  const rates = drivers.value
    .map((d) => {
      const pct = formatPct(d.rate)
      if (!pct) return null
      const short =
        d.metric === 'claims' ? 'reclamos' : d.metric === 'cancellations' ? 'cancel.' : 'despacho'
      return `${short} ${pct}`
    })
    .filter(Boolean)
  if (rates.length) parts.push(rates.join(' · '))
  else if (evaluation.value?.worst_band) parts.push(`banda ${bandLabel(evaluation.value.worst_band)}`)
  return parts.length ? parts.join(' · ') : 'Sin métricas aún'
})

const peSummaryText = computed(() => {
  const totals = peSummary.value.totals_by_color ?? {}
  const entries = Object.entries(totals)
  if (!peSummary.value.enabled) return 'Activa Experiencia de compra en sync de publicaciones'
  if (!entries.length && !peSummary.value.without_data) return 'Sin datos de experiencia'
  const colored = entries.map(([color, count]) => `${color} ${count}`).join(' · ')
  const rest = peSummary.value.without_data ? ` · sin dato ${peSummary.value.without_data}` : ''
  return `${colored || '—'}${rest}`
})

const openSection = (section) => {
  emit('open-account-section', section)
}

const localColor = ref(props.connection.color)
watch(
  () => props.connection.color,
  (next) => {
    localColor.value = next
  },
)

const accentColor = computed(() => resolveConnectionColor(localColor.value))

const connectionForPicker = computed(() => ({
  ...props.connection,
  color: localColor.value,
}))

const onColorUpdated = (color) => {
  localColor.value = color
}
</script>

<template>
  <div
    class="flex min-h-0 flex-1 flex-col overflow-auto"
    :class="compact ? 'space-y-3 px-3 py-3 sm:px-4' : 'space-y-4 px-4 py-4 sm:px-6'"
  >
    <div class="flex flex-wrap items-start gap-2.5">
      <div class="relative shrink-0">
        <div
          class="flex size-9 items-center justify-center overflow-hidden rounded-full bg-secondary text-[10px] font-semibold text-muted-foreground"
          :style="{ boxShadow: `0 0 0 2px ${accentColor}` }"
        >
          <img
            v-if="connection.avatar_url"
            :src="connection.avatar_url"
            :alt="accountTitle"
            class="h-full w-full object-cover"
            referrerpolicy="no-referrer"
            loading="lazy"
          />
          <span v-else>{{ initials }}</span>
        </div>
        <span
          class="absolute -bottom-0.5 -right-0.5 size-2.5 rounded-full border-2 border-white"
          :style="{ backgroundColor: accentColor }"
          aria-hidden="true"
        />
      </div>
      <div class="min-w-0 flex-1 space-y-1">
        <div class="flex flex-wrap items-center gap-1.5">
          <span
            class="inline-flex h-5 items-center rounded-full bg-slate-100 px-1.5 text-[10px] font-medium text-slate-600"
          >
            {{ platformName }}
          </span>
          <Badge
            v-if="powerSellerLabel"
            variant="secondary"
            class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
          >
            {{ powerSellerLabel }}
          </Badge>
          <Badge
            v-if="reputationLabel"
            :variant="reputationBadgeVariant"
            class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
          >
            {{ reputationLabel }}
          </Badge>
        </div>
        <StackedData v-bind="accountStacked" primary-class="text-[13px]" />
        <a
          v-if="connection.permalink"
          :href="connection.permalink"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-block text-[11px] font-medium text-brand hover:underline"
        >
          Ver perfil en Mercado Libre
        </a>
      </div>
    </div>

    <ConnectionHealth
      :status="connection.status"
      :freshness-status="connection.freshness_status"
      :needs-reauthorization="connection.needs_reauthorization"
      :last-synced-at="connection.last_synced_at"
    />

    <section
      v-if="connection.provider === 'mercadolibre'"
      class="space-y-2"
    >
      <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
        Cuenta Mercado Libre
      </h3>

      <button
        type="button"
        class="flex w-full items-start justify-between gap-3 rounded-lg border border-border/80 bg-slate-50/60 px-3 py-2.5 text-left transition hover:bg-slate-50"
        @click="openSection('profile')"
      >
        <div class="min-w-0">
          <p class="text-[12px] font-semibold text-slate-900">Cuenta</p>
          <p class="mt-0.5 text-[11px] leading-relaxed text-slate-500">{{ profileSummary }}</p>
        </div>
        <span class="shrink-0 text-[11px] font-medium text-brand">Ver detalle</span>
      </button>

      <button
        type="button"
        class="flex w-full items-start justify-between gap-3 rounded-lg border border-border/80 bg-white px-3 py-2.5 text-left transition hover:bg-slate-50"
        @click="openSection('status')"
      >
        <div class="min-w-0">
          <p class="text-[12px] font-semibold text-slate-900">Estado y permisos</p>
          <p class="mt-0.5 text-[11px] leading-relaxed text-slate-500">{{ statusSummary }}</p>
        </div>
        <span class="shrink-0 text-[11px] font-medium text-brand">Ver detalle</span>
      </button>

      <button
        type="button"
        class="flex w-full items-start justify-between gap-3 rounded-lg border border-border/80 bg-white px-3 py-2.5 text-left transition hover:bg-slate-50"
        @click="openSection('reputation')"
      >
        <div class="min-w-0">
          <p class="text-[12px] font-semibold text-slate-900">Reputación</p>
          <p class="mt-0.5 text-[11px] leading-relaxed text-slate-500">{{ reputationSummary }}</p>
        </div>
        <span class="shrink-0 text-[11px] font-medium text-brand">Ver detalle</span>
      </button>

      <button
        type="button"
        class="flex w-full items-start justify-between gap-3 rounded-lg border border-border/80 bg-white px-3 py-2.5 text-left transition hover:bg-slate-50"
        @click="openSection('purchase_experience')"
      >
        <div class="min-w-0">
          <p class="text-[12px] font-semibold text-slate-900">Experiencia de compra</p>
          <p class="mt-0.5 text-[11px] leading-relaxed text-slate-500">{{ peSummaryText }}</p>
        </div>
        <span class="shrink-0 text-[11px] font-medium text-brand">Ver detalle</span>
      </button>
    </section>

    <div class="rounded-lg border border-border/80 bg-slate-50/60 px-3 py-2.5">
      <ConnectionColorPicker
        :connection="connectionForPicker"
        @updated="onColorUpdated"
      />
    </div>

    <SyncConfigurator
      :connection-id="connection.id"
      :catalog="catalog"
      :profiles="profiles"
      :compact="compact"
    />
  </div>
</template>
