<script setup>
import { computed } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import AccountDetailRows from '@/Components/Connections/AccountDetailRows.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import { allowLabel, boolLabel, formatDateTime } from '@/lib/accountProfileDisplay'

const props = defineProps({
  show: { type: Boolean, default: false },
  connection: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const profile = computed(() => props.connection?.account_profile ?? null)
const status = computed(() => profile.value?.status ?? null)
const title = computed(
  () => profile.value?.nickname || props.connection?.display_name || 'Estado',
)

function permissionRows(block, label) {
  if (!block) {
    return [
      { label: `${label}`, value: null },
    ]
  }
  const codes = Array.isArray(block.codes) && block.codes.length ? block.codes.join(', ') : null
  const rows = [
    { label: `${label}`, value: allowLabel(block.allow) },
    { label: `${label} codes`, value: codes },
  ]
  if (block.immediate_payment) {
    rows.push({
      label: `${label} pago inm.`,
      value: boolLabel(block.immediate_payment.required),
    })
    const reasons = Array.isArray(block.immediate_payment.reasons)
      ? block.immediate_payment.reasons.join(', ')
      : null
    if (reasons) rows.push({ label: `${label} razones`, value: reasons })
  }
  return rows
}

const overviewRows = computed(() => {
  const s = status.value ?? {}
  return [
    { label: 'Site status', value: s.site_status },
    { label: 'Email confirmado', value: boolLabel(s.confirmed_email) },
    { label: 'Acción requerida', value: s.required_action },
    { label: 'Tipo status', value: s.user_type },
    { label: 'Mercado Envíos', value: s.mercadoenvios },
    { label: 'MP cuenta', value: s.mercadopago_account_type },
    { label: 'MP TC', value: boolLabel(s.mercadopago_tc_accepted) },
    { label: 'Pago inmediato', value: boolLabel(s.immediate_payment) },
    { label: 'Carrito comprar', value: s.shopping_cart?.buy },
    { label: 'Carrito vender', value: s.shopping_cart?.sell },
  ]
})

const permissionAllRows = computed(() => {
  const s = status.value ?? {}
  return [
    ...permissionRows(s.sell, 'Vender'),
    ...permissionRows(s.list, 'Publicar'),
    ...permissionRows(s.buy, 'Comprar'),
    ...permissionRows(s.billing, 'Facturación'),
  ]
})

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
    :accessibility-title="`Estado · ${title}`"
    @close="emit('close')"
  >
    <template #header>
      <p class="truncate text-[13px] font-semibold tracking-tight text-slate-900">
        Estado · {{ title }}
      </p>
    </template>

    <div class="space-y-4 px-4 py-3 sm:px-5">
      <p v-if="!status" class="text-[12px] text-slate-500">
        Aún no hay estado sincronizado. Ejecuta el enrich de la conexión.
      </p>

      <template v-else>
        <p v-if="syncedAt" class="text-[10px] text-slate-400">Sync {{ syncedAt }}</p>

        <section class="space-y-1.5">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">General</h3>
          <AccountDetailRows :rows="overviewRows" />
        </section>

        <section class="space-y-1.5">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Permisos</h3>
          <AccountDetailRows :rows="permissionAllRows" />
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
