<script setup>
import { computed, ref } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import CashDiffTable from '@/Components/Domain/CashDiffTable.vue'
import { formatDateTime } from '@/lib/utils'

const props = defineProps({
  payment: { type: Object, required: true },
  diff: { type: Object, default: null },
  aligned: { type: Object, default: null },
  cashoutTrail: { type: Object, default: null },
  ledgerEntries: { type: Array, default: () => [] },
  releaseBatch: { type: Object, default: null },
})

const cohortOpen = ref(false)

const currency = computed(() => props.payment?.currency_code ?? props.releaseBatch?.currency ?? 'MXN')

const amounts = computed(() => ({
  revenue: props.aligned?.revenue ?? props.payment.transaction_amount,
  fee: props.aligned?.fee ?? props.payment.marketplace_fee_amount,
  shipping: props.aligned?.shipping ?? props.payment.shipping_cost_amount,
  tax: props.aligned?.tax ?? props.payment.tax_amount,
  net: props.aligned?.net ?? props.payment.net_received_amount,
}))

const trail = computed(() => props.cashoutTrail ?? {})
const collection = computed(() => trail.value.collection ?? null)
const mpRelease = computed(() => trail.value.mp_release ?? null)
const bankWithdrawal = computed(() => trail.value.bank_withdrawal ?? null)

const batchPayments = computed(() => props.releaseBatch?.payments ?? [])
const totalCount = computed(() => props.releaseBatch?.total_count ?? props.releaseBatch?.payments_count ?? 0)
const truncated = computed(() => Boolean(props.releaseBatch?.truncated))
const hasBatch = computed(() => totalCount.value > 1)

function statusLabel(status) {
  if (status === 'balanced' || status === 'matched') return 'OK'
  if (status === 'short') return 'Falta monto'
  if (status === 'over') return 'Sobra monto'
  if (status === 'pending' || status === 'incomplete') return 'Pendiente'
  if (status === 'reserved' || status === 'in_mediation') return 'En mediación'
  return status || '—'
}

const releaseHasDispute = computed(() =>
  Boolean(mpRelease.value?.has_dispute)
  || (mpRelease.value?.movements ?? []).some((m) => m.concept === 'dispute')
  || props.payment?.status === 'in_mediation',
)

const walletTotalIsZero = computed(() => {
  const n = Number(mpRelease.value?.total_released)
  return Number.isFinite(n) && Math.abs(n) < 0.01
})

function stepTone(status, missing) {
  if (missing) return 'border-slate-200 bg-slate-50/80'
  if (status === 'balanced' || status === 'matched') return 'border-emerald-200 bg-emerald-50/50'
  if (status === 'short' || status === 'over') return 'border-rose-200 bg-rose-50/50'
  return 'border-amber-200 bg-amber-50/50'
}

async function copyId(value) {
  if (!value || typeof navigator === 'undefined' || !navigator.clipboard) return
  try {
    await navigator.clipboard.writeText(String(value))
  } catch {
    // ignore
  }
}
</script>

<template>
  <div class="space-y-5 overflow-y-auto p-4 text-sm">
    <section class="space-y-3">
      <div>
        <h4 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          Dinero de Meli a ti
        </h4>
        <p class="mt-1 text-[11px] text-muted-foreground">
          Cadena collections → liberación en saldo MP → retiro al banco. El retiro se atribuye por FIFO
          (MP no reporta payout por orden).
        </p>
      </div>

      <!-- Step 1: collections -->
      <div
        class="rounded-lg border px-3 py-2.5"
        :class="stepTone(collection?.status_recon, !collection)"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-[11px] font-semibold uppercase tracking-wide">1. Cobro (collections)</p>
          <Badge variant="outline">{{ statusLabel(collection?.status_recon) }}</Badge>
        </div>
        <p class="mt-1 text-[11px] text-muted-foreground">Pago del comprador a Mercado Pago</p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="font-mono text-xs underline-offset-2 hover:underline"
            @click="copyId(collection?.external_payment_id || payment.external_payment_id)"
          >
            {{ collection?.external_payment_id || payment.external_payment_id }}
          </button>
          <MoneyText :amount="collection?.net_amount ?? amounts.net" :currency="currency" />
        </div>
        <p v-if="collection?.paid_at" class="mt-1 text-[11px] text-muted-foreground">
          {{ formatDateTime(collection.paid_at) }}
        </p>
      </div>

      <!-- Step 2: MP release -->
      <div
        class="rounded-lg border px-3 py-2.5"
        :class="stepTone(mpRelease?.status, !mpRelease)"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-[11px] font-semibold uppercase tracking-wide">2. Liberación (saldo MP)</p>
          <Badge variant="outline">
            {{ mpRelease ? statusLabel(mpRelease.status) : 'Sin asiento' }}
          </Badge>
        </div>
        <template v-if="mpRelease">
          <p class="mt-1 text-[11px] text-muted-foreground">
            <template v-if="releaseHasDispute || walletTotalIsZero">
              Lo que MP asentó en tu saldo por esta venta · retenido
            </template>
            <template v-else>
              Lo que MP acreditó a tu saldo por esta venta
              <span v-if="mpRelease.is_released"> · en saldo</span>
              <span v-else> · asentado, liberación pendiente</span>
            </template>
          </p>
          <ul
            v-if="mpRelease.movements?.length"
            class="mt-2 space-y-1.5"
          >
            <li
              v-for="m in mpRelease.movements"
              :key="m.ledger_entry_id || `${m.external_source_id}-${m.transaction_type}`"
              class="flex items-start justify-between gap-2 text-[12px]"
            >
              <div>
                <span class="font-medium">{{ m.concept_label }}</span>
                <span
                  v-if="m.concept_hint"
                  class="mt-0.5 block text-[10px] leading-snug text-muted-foreground"
                >
                  {{ m.concept_hint }}
                </span>
              </div>
              <MoneyText :amount="m.net_amount" :currency="currency" />
            </li>
          </ul>
          <div
            v-else
            class="mt-2 flex flex-wrap items-center gap-2"
          >
            <button
              type="button"
              class="font-mono text-xs underline-offset-2 hover:underline"
              @click="copyId(mpRelease.external_source_id)"
            >
              {{ mpRelease.external_source_id || '—' }}
            </button>
            <MoneyText :amount="mpRelease.net_amount" :currency="currency" />
          </div>
          <p
            v-if="mpRelease.total_released != null"
            class="mt-2 text-[12px] font-semibold"
          >
            Total en saldo MP:
            <MoneyText :amount="mpRelease.total_released" :currency="currency" />
          </p>
          <p v-if="mpRelease.diff != null && !mpRelease.movements?.length" class="mt-1 text-[11px] text-muted-foreground">
            Diff vs esperado:
            <MoneyText :amount="mpRelease.diff" :currency="currency" />
          </p>
          <p v-if="mpRelease.released_at || mpRelease.occurred_at" class="mt-1 text-[11px] text-muted-foreground">
            {{ formatDateTime(mpRelease.released_at || mpRelease.occurred_at) }}
          </p>
        </template>
        <p v-else class="mt-1 text-[11px] text-muted-foreground">
          Aún no hay settlement/release en Ledger. Sync reportes MP en Caja.
        </p>
      </div>

      <!-- Step 3: bank withdrawal -->
      <div
        class="rounded-lg border px-3 py-2.5"
        :class="stepTone(bankWithdrawal?.status, !bankWithdrawal)"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-[11px] font-semibold uppercase tracking-wide">3. Retiro al banco</p>
          <Badge variant="outline">
            {{ bankWithdrawal ? statusLabel(bankWithdrawal.status) : 'Sin payout' }}
          </Badge>
        </div>
        <template v-if="bankWithdrawal">
          <p class="mt-1 text-[11px] text-muted-foreground">
            Payout real MP → banco (ID del reporte) · atribución FIFO a órdenes
          </p>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="font-mono text-xs underline-offset-2 hover:underline"
              @click="copyId(bankWithdrawal.external_source_id)"
            >
              {{ bankWithdrawal.external_source_id || '—' }}
            </button>
            <span class="text-[11px] text-muted-foreground">esta orden</span>
            <MoneyText :amount="bankWithdrawal.allocated_amount" :currency="currency" />
          </div>
          <p class="mt-1 text-[11px] text-muted-foreground">
            Total del retiro:
            <MoneyText :amount="bankWithdrawal.withdrawal_net_total" :currency="currency" />
          </p>
          <p v-if="bankWithdrawal.diff != null" class="mt-1 text-[11px] text-muted-foreground">
            Diff vs esperado:
            <MoneyText :amount="bankWithdrawal.diff" :currency="currency" />
          </p>
          <p v-if="bankWithdrawal.occurred_at" class="mt-1 text-[11px] text-muted-foreground">
            {{ formatDateTime(bankWithdrawal.occurred_at) }}
          </p>
        </template>
        <p v-else class="mt-1 text-[11px] text-muted-foreground">
          Sin withdrawal atribuido. Hace falta que el pago esté liberado (`is_released`) y exista
          un retiro en reportes MP; luego corre reconciliación.
        </p>
      </div>
    </section>

    <section class="space-y-2">
      <button
        type="button"
        class="flex w-full items-center justify-between rounded-md border border-slate-200 px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground hover:bg-slate-50"
        @click="cohortOpen = !cohortOpen"
      >
        <span>Otras ventas liberadas en la misma hora</span>
        <span>{{ cohortOpen ? '−' : '+' }}</span>
      </button>
      <div v-if="cohortOpen" class="space-y-2">
        <p class="text-[11px] text-muted-foreground">
          Cohort por hora de <span class="font-mono">money_release_date</span> (como “Liberación de dinero” en MP).
          No es el depósito bancario.
          <template v-if="hasBatch">
            {{ truncated ? `${totalCount} pagos (vista truncada)` : `${totalCount} pagos` }}.
          </template>
        </p>
        <div v-if="batchPayments.length" class="overflow-hidden rounded-xl border border-slate-200/70">
          <table class="min-w-full text-left text-[12px]">
            <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-muted-foreground">
              <tr>
                <th class="px-2.5 py-1.5">Payment</th>
                <th class="px-2.5 py-1.5">Orden</th>
                <th class="px-2.5 py-1.5 text-right">Neto</th>
                <th class="px-2.5 py-1.5">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in batchPayments"
                :key="row.id"
                class="border-t border-slate-100"
                :class="row.is_current ? 'bg-amber-50/70' : ''"
              >
                <td class="px-2.5 py-1.5 font-mono text-[11px]">
                  {{ row.external_payment_id }}
                  <span v-if="row.is_current" class="ml-1 text-[10px] text-amber-800">esta</span>
                </td>
                <td class="px-2.5 py-1.5 font-mono text-[11px] text-muted-foreground">
                  {{ row.external_order_id || row.order_id || '—' }}
                </td>
                <td class="px-2.5 py-1.5 text-right font-mono">
                  <MoneyText :amount="row.net_received_amount" :currency="currency" />
                </td>
                <td class="px-2.5 py-1.5">
                  <Badge variant="outline">{{ row.reconciliation_status || '—' }}</Badge>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="space-y-2">
      <h4 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
        Cobro de esta venta (collections)
      </h4>
      <p class="text-[11px] text-muted-foreground">
        Neto según collections (pago del comprador). No es depósito bancario.
      </p>
      <div class="grid gap-2 sm:grid-cols-2">
        <div>
          <p class="text-[10px] uppercase text-muted-foreground">Ingreso</p>
          <MoneyText :amount="amounts.revenue" :currency="currency" />
        </div>
        <div>
          <p class="text-[10px] uppercase text-muted-foreground">Fee</p>
          <MoneyText :amount="amounts.fee" :currency="currency" />
        </div>
        <div>
          <p class="text-[10px] uppercase text-muted-foreground">Envío (seller)</p>
          <MoneyText :amount="amounts.shipping" :currency="currency" />
        </div>
        <div>
          <p class="text-[10px] uppercase text-muted-foreground">Impuestos</p>
          <MoneyText :amount="amounts.tax" :currency="currency" />
        </div>
        <div>
          <p class="text-[10px] uppercase text-muted-foreground">Neto cobrado</p>
          <MoneyText :amount="amounts.net" :currency="currency" />
        </div>
        <div>
          <p class="text-[10px] uppercase text-muted-foreground">Diff neto</p>
          <MoneyText :amount="payment.diff_amount" :currency="currency" />
        </div>
      </div>

      <CashDiffTable
        v-if="diff"
        :rows="diff.rows ?? []"
        :currency="diff.currency"
        :status="diff.status"
        :banner-diff="diff.banner_diff"
      />
    </section>
  </div>
</template>
