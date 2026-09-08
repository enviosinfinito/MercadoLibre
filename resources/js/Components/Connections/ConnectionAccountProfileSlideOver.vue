<script setup>
import { computed } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import AccountDetailRows from '@/Components/Connections/AccountDetailRows.vue'
import Badge from '@/Components/ui/Badge.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { boolLabel, formatDateTime, formatPhone } from '@/lib/accountProfileDisplay'

const props = defineProps({
  show: { type: Boolean, default: false },
  connection: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const profile = computed(() => props.connection?.account_profile ?? null)

const title = computed(
  () => profile.value?.nickname || props.connection?.display_name || 'Cuenta ML',
)

const identityRows = computed(() => {
  const p = profile.value ?? {}
  const name = [p.first_name, p.last_name].filter(Boolean).join(' ') || null
  return [
    { label: 'ID', value: p.id || props.connection?.external_user_id },
    { label: 'Nickname', value: p.nickname || props.connection?.display_name },
    { label: 'Nombre', value: name },
    { label: 'País', value: p.country_id },
    { label: 'Registro', value: formatDateTime(p.registration_date) },
    { label: 'Tipo', value: p.user_type },
    { label: 'Experiencia', value: p.seller_experience },
    { label: 'Puntos', value: p.points != null ? String(p.points) : null },
    { label: 'Site', value: props.connection?.site_id },
  ]
})

const contactRows = computed(() => {
  const p = profile.value ?? {}
  const id = p.identification
  return [
    { label: 'Email', value: p.email },
    { label: 'Teléfono', value: formatPhone(p.phone) },
    { label: 'Tel. alt.', value: formatPhone(p.alternative_phone) },
    {
      label: 'Identificación',
      value: id ? [id.type, id.number].filter(Boolean).join(' ') : null,
    },
  ]
})

const addressRows = computed(() => {
  const a = profile.value?.address ?? {}
  return [
    { label: 'Calle', value: a.address },
    { label: 'Ciudad', value: a.city },
    { label: 'Estado', value: a.state },
    { label: 'C.P.', value: a.zip_code },
  ]
})

const companyRows = computed(() => {
  const c = profile.value?.company ?? {}
  const bill = profile.value?.bill_data ?? {}
  return [
    { label: 'Razón social', value: c.corporate_name },
    { label: 'Marca', value: c.brand_name },
    { label: 'ID fiscal', value: c.identification },
    { label: 'Tax ciudad', value: c.city_tax_id },
    { label: 'Tax estado', value: c.state_tax_id },
    { label: 'Tipo cliente', value: c.cust_type_id },
    { label: 'Soft descriptor', value: c.soft_descriptor },
    { label: 'Nota de crédito', value: boolLabel(bill.accept_credit_note) },
  ]
})

const creditRows = computed(() => {
  const c = profile.value?.credit ?? {}
  return [
    { label: 'Nivel', value: c.credit_level_id },
    { label: 'Rank', value: c.rank },
    { label: 'Consumido', value: c.consumed != null ? String(c.consumed) : null },
  ]
})

const tags = computed(() =>
  Array.isArray(profile.value?.tags) ? profile.value.tags : [],
)

const syncedAt = computed(() => formatDateTime(props.connection?.account_profile_synced_at))
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    compact-header
    fill-height
    mobile-full-bleed
    stackable
    :accessibility-title="`Cuenta · ${title}`"
    @close="emit('close')"
  >
    <template #header>
      <p class="truncate text-[13px] font-semibold tracking-tight text-slate-900">
        Cuenta · {{ title }}
      </p>
    </template>

    <div class="space-y-4 px-4 py-3 sm:px-5">
      <p v-if="!profile" class="text-[12px] text-slate-500">
        Aún no hay perfil sincronizado. Ejecuta el enrich de la conexión.
      </p>

      <template v-else>
        <p v-if="syncedAt" class="text-[10px] text-slate-400">Sync {{ syncedAt }}</p>

        <section class="space-y-1.5">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Identidad</h3>
          <AccountDetailRows :rows="identityRows" />
          <div v-if="tags.length" class="flex flex-wrap gap-1 pt-1">
            <Badge
              v-for="tag in tags"
              :key="tag"
              variant="secondary"
              class="h-5 rounded-full px-1.5 text-[10px]"
            >
              {{ tag }}
            </Badge>
          </div>
        </section>

        <section class="space-y-1.5">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Contacto</h3>
          <AccountDetailRows :rows="contactRows" />
        </section>

        <section class="space-y-1.5">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Dirección</h3>
          <AccountDetailRows :rows="addressRows" />
        </section>

        <section class="space-y-1.5">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Empresa</h3>
          <AccountDetailRows :rows="companyRows" />
        </section>

        <section class="space-y-1.5">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Crédito</h3>
          <AccountDetailRows :rows="creditRows" />
        </section>
      </template>
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
