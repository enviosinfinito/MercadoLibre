<script setup lang="ts">
import { computed, ref } from 'vue';
import { ChevronRight } from 'lucide-vue-next';
import MoneyText from '@/Components/App/MoneyText.vue';

export interface CashDiffRow {
    concept: string;
    expected?: string | number | null;
    actual?: string | number | null;
    diff?: string | number | null;
    provenance?: string | null;
    note?: string | null;
}

const props = withDefaults(
    defineProps<{
        rows: CashDiffRow[];
        currency?: string;
        status?: string | null;
        bannerDiff?: string | number | null;
        footnote?: string | null;
    }>(),
    {
        currency: 'MXN',
        status: null,
        bannerDiff: null,
        footnote:
            'El envío que te cobran ya está en el cobro. “Envío que pagó el comprador” es aparte, del checkout.',
    },
);

const open = ref(false);
const currency = computed(() => props.currency ?? 'MXN');
const TOLERANCE = 0.01;

function absDiff(value: string | number | null | undefined): number | null {
    if (value == null || value === '') return null;
    const n = Number(value);
    if (Number.isNaN(n)) return null;
    return Math.abs(n);
}

function isMismatch(row: CashDiffRow): boolean {
    if (row.expected == null || row.actual == null || row.diff == null || row.diff === '') {
        return false;
    }
    const n = absDiff(row.diff);
    return n != null && n >= TOLERANCE;
}

function isHoldRow(row: CashDiffRow): boolean {
    return row.concept === 'Reserva / reclamo';
}

const mismatchCount = computed(() => props.rows.filter(isMismatch).length);

const tone = computed(() => {
    const status = props.status ?? 'incomplete';
    if (status === 'reserved' || status === 'in_mediation') return 'hold' as const;
    if (status === 'balanced') return 'ok' as const;
    if (status === 'short' || status === 'over') return 'fail' as const;
    return 'wait' as const;
});

const pill = computed(() => {
    if (tone.value === 'ok') return 'Cuadra';
    if (tone.value === 'fail') return 'Falla';
    if (tone.value === 'hold') return 'Retenido';
    return 'Pendiente';
});

const subtitle = computed(() => {
    if (tone.value === 'ok') return 'Esperado vs cobro real';
    if (tone.value === 'fail') {
        const n = mismatchCount.value;
        const hint = props.status === 'over' ? 'cobró de más' : 'falta cobrar';
        return n ? `${n} diferencia${n === 1 ? '' : 's'} · ${hint}` : hint;
    }
    if (tone.value === 'hold') return 'En mediación · dinero no disponible';
    return 'Reconciliación incompleta';
});

const netRow = computed(() => props.rows.find((r) => r.concept === 'Neto cobrado') ?? null);

function toneForDiff(diff: string | number | null | undefined): string {
    const n = absDiff(diff);
    if (n == null) return 'text-slate-400';
    if (n < TOLERANCE) return 'text-slate-500';
    const signed = Number(diff);
    return signed > 0 ? 'text-rose-700' : 'text-amber-800';
}

function toggle() {
    open.value = !open.value;
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-slate-200/80 bg-white">
        <button
            type="button"
            class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50/80"
            :aria-expanded="open"
            @click="toggle"
        >
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
                        Cobro
                    </span>
                    <span
                        class="rounded-md px-1.5 py-0.5 text-[10px] font-semibold"
                        :class="{
                            'bg-emerald-100 text-emerald-800': tone === 'ok',
                            'bg-rose-100 text-rose-800': tone === 'fail',
                            'bg-amber-100 text-amber-900': tone === 'hold',
                            'bg-slate-100 text-slate-600': tone === 'wait',
                        }"
                    >
                        {{ pill }}
                    </span>
                </div>
                <p class="mt-0.5 text-[11px] text-slate-500">{{ subtitle }}</p>
            </div>
            <div class="shrink-0 text-right">
                <MoneyText
                    v-if="netRow?.actual != null"
                    class="block text-[13px] font-semibold tabular-nums text-slate-900"
                    :amount="netRow.actual"
                    :currency="currency"
                />
                <p
                    v-if="bannerDiff != null"
                    class="mt-0.5 text-[10px] tabular-nums"
                    :class="toneForDiff(bannerDiff)"
                >
                    Δ
                    <MoneyText :amount="bannerDiff" :currency="currency" />
                </p>
            </div>
            <ChevronRight
                class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                :class="open ? 'rotate-90' : ''"
            />
        </button>

        <div v-if="open" class="border-t border-slate-100">
            <table class="min-w-full text-left text-[12px]">
                <thead class="bg-slate-50/80 text-[10px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-3 py-1.5 font-medium">Concepto</th>
                        <th class="px-3 py-1.5 text-right font-medium">Esperado</th>
                        <th class="px-3 py-1.5 text-right font-medium">Real</th>
                        <th class="px-3 py-1.5 text-right font-medium">Δ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.concept"
                        class="border-t border-slate-100"
                        :class="{
                            'bg-rose-50/90': isMismatch(row),
                            'bg-amber-50/80': !isMismatch(row) && isHoldRow(row),
                        }"
                    >
                        <td class="px-3 py-1.5 align-top">
                            <div
                                class="flex items-start gap-1.5"
                                :class="isMismatch(row) ? 'font-semibold text-rose-950' : 'text-slate-800'"
                            >
                                <span
                                    v-if="isMismatch(row)"
                                    class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-rose-500"
                                />
                                <span
                                    v-else-if="isHoldRow(row)"
                                    class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"
                                />
                                <span>{{ row.concept }}</span>
                            </div>
                            <div
                                v-if="row.note"
                                class="mt-0.5 text-[10px] leading-snug text-slate-500"
                            >
                                {{ row.note }}
                            </div>
                        </td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-slate-700">
                            <MoneyText
                                v-if="row.expected != null"
                                :amount="row.expected"
                                :currency="currency"
                            />
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-slate-700">
                            <MoneyText
                                v-if="row.actual != null"
                                :amount="row.actual"
                                :currency="currency"
                            />
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td
                            class="px-3 py-1.5 text-right tabular-nums"
                            :class="[toneForDiff(row.diff), isMismatch(row) ? 'font-semibold' : '']"
                        >
                            <MoneyText
                                v-if="row.diff != null"
                                :amount="row.diff"
                                :currency="currency"
                            />
                            <span v-else class="text-slate-400">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="$slots.expanded" class="border-t border-slate-100 px-3 py-2">
                <slot name="expanded" />
            </div>

            <p
                v-if="footnote"
                class="border-t border-slate-100 px-3 py-2 text-[10px] leading-snug text-slate-500"
            >
                {{ footnote }}
            </p>
        </div>
    </div>
</template>
