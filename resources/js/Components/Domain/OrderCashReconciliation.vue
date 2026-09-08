<script setup>
import { onMounted, ref, watch } from 'vue'
import CashDiffTable from '@/Components/Domain/CashDiffTable.vue'
import MarketplacePaymentDetail from '@/Components/Domain/MarketplacePaymentDetail.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import { jsonFetch } from '@/lib/jsonFetch'

const props = defineProps({
  orderId: { type: [Number, String], required: true },
})

const emit = defineEmits(['wallet'])

const loading = ref(false)
const error = ref(null)
const diff = ref(null)

const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref(null)
const detail = ref(null)
const detailPaymentId = ref(null)

async function load() {
  if (!props.orderId) return
  loading.value = true
  error.value = null
  emit('wallet', null)
  try {
    const data = await jsonFetch(route('finance.cash.orders.diff', props.orderId))
    diff.value = data?.diff ?? null
    const walletRow = (diff.value?.rows ?? []).find((r) => r.concept === 'Total en saldo MP')
    emit('wallet', {
      total: diff.value?.wallet_total ?? walletRow?.actual ?? null,
      status: diff.value?.status ?? null,
      reserved: diff.value?.reserved === true || diff.value?.status === 'reserved',
      has_shipping_credit: diff.value?.has_shipping_credit === true,
    })
  } catch (e) {
    error.value = e?.message ?? 'No se pudo cargar reconciliación'
    diff.value = null
    emit('wallet', null)
  } finally {
    loading.value = false
  }
}

async function openPayment(payment) {
  if (!payment?.id) return
  detailPaymentId.value = payment.id
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = null
  detail.value = null
  try {
    detail.value = await jsonFetch(route('finance.cash.payments.show', payment.id))
  } catch (e) {
    detailError.value = e?.message ?? 'No se pudo cargar el pago'
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  detailOpen.value = false
}

function retryDetail() {
  if (detailPaymentId.value) {
    openPayment({ id: detailPaymentId.value })
  }
}

function paymentStatusLabel(p) {
  if (p?.status === 'in_mediation') return 'En mediación'
  const map = {
    balanced: 'Cuadrado',
    short: 'Faltante',
    over: 'Sobrante',
    pending: 'Pendiente',
    incomplete: 'Incompleto',
    reserved: 'Retenido',
    in_mediation: 'En mediación',
  }
  return map[p?.reconciliation_status] || p?.reconciliation_status || '—'
}

onMounted(load)
watch(() => props.orderId, load)
</script>

<template>
  <div>
    <p v-if="loading" class="rounded-xl border border-slate-200/80 bg-white px-3 py-2.5 text-[11px] text-slate-500">
      Cargando cobro…
    </p>
    <p
      v-else-if="error"
      class="rounded-xl border border-rose-200 bg-rose-50/80 px-3 py-2.5 text-[11px] text-rose-800"
    >
      {{ error }}
    </p>
    <CashDiffTable
      v-else-if="diff"
      :rows="diff.rows ?? []"
      :currency="diff.currency"
      :status="diff.status"
      :banner-diff="diff.banner_diff"
    >
      <template v-if="diff.payments?.length" #expanded>
        <p class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
          Pago
        </p>
        <ul class="mt-1 space-y-1">
          <li
            v-for="p in diff.payments"
            :key="p.id"
          >
            <button
              type="button"
              class="flex w-full items-center justify-between gap-2 rounded-md px-1 py-0.5 text-left text-[12px] hover:bg-slate-50"
              @click="openPayment(p)"
            >
              <span class="font-mono text-[11px] text-slate-700 underline-offset-2 hover:underline">
                {{ p.external_payment_id }}
              </span>
              <span class="text-[11px] text-slate-500">{{ paymentStatusLabel(p) }}</span>
              <MoneyText
                class="tabular-nums text-slate-800"
                :amount="p.net_received_amount"
                :currency="diff.currency"
              />
            </button>
          </li>
        </ul>
      </template>
    </CashDiffTable>
  </div>

  <SlideOverShell
    :show="detailOpen"
    title="Cobro / IDs"
    :loading="detailLoading"
    :error="detailError"
    @close="closeDetail"
    @retry="retryDetail"
  >
    <MarketplacePaymentDetail
      v-if="detail?.payment"
      :payment="detail.payment"
      :diff="detail.diff"
      :aligned="detail.aligned"
      :cashout-trail="detail.cashout_trail"
      :ledger-entries="detail.ledger_entries ?? []"
      :release-batch="detail.release_batch"
    />
  </SlideOverShell>
</template>
