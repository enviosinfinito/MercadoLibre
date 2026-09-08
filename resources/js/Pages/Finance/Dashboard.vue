<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import MissingCostAlertBanner from '@/Components/Catalog/MissingCostAlertBanner.vue';
import Card from '@/Components/ui/Card.vue';
import MoneyText from '@/Components/App/MoneyText.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        summary?: {
            expected_profit?: string | number | null;
            revenue?: string | number | null;
            fees?: string | number | null;
            cogs?: string | number | null;
            incomplete_orders?: number;
            currency?: string;
            expected_net?: string | number | null;
            settled_net?: string | number | null;
            released_net?: string | number | null;
            withdrawn_net?: string | number | null;
            cash_diff?: string | number | null;
            cash_status?: string | null;
            payments_short?: number;
            payments_over?: number;
            payments_balanced?: number;
            payments_incomplete?: number;
        };
        listings_without_cost?: number;
        latest_run?: Record<string, unknown> | null;
        capabilities?: Array<{
            connection_id: number;
            capability_key: string;
            enabled: boolean;
            meta?: Record<string, unknown> | null;
        }>;
    }>(),
    {
        summary: () => ({
            expected_profit: null,
            revenue: null,
            fees: null,
            cogs: null,
            incomplete_orders: 0,
            currency: 'MXN',
            expected_net: null,
            settled_net: null,
            released_net: null,
            withdrawn_net: null,
            cash_diff: null,
            cash_status: 'incomplete',
            payments_short: 0,
            payments_over: 0,
            payments_balanced: 0,
            payments_incomplete: 0,
        }),
        listings_without_cost: 0,
        latest_run: null,
        capabilities: () => [],
    },
);
</script>

<template>
    <Head title="Finance" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Finance"
                    description="P&L esperado y reconciliación de caja del canal (esperado vs real)."
                >
                    <template #actions>
                        <Link :href="route('finance.cash.index')">
                            <Button size="sm" variant="outline">Ver movimientos de caja</Button>
                        </Link>
                    </template>
                </PageHeader>

                <MissingCostAlertBanner :count="listings_without_cost" />

                <div
                    v-if="(summary.payments_short ?? 0) + (summary.payments_over ?? 0) > 0"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900"
                >
                    {{ summary.payments_short ?? 0 }} pagos short ·
                    {{ summary.payments_over ?? 0 }} over ·
                    {{ summary.payments_incomplete ?? 0 }} incompletos.
                    <Link :href="route('finance.cash.index')" class="ml-2 underline">Revisar caja</Link>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <p class="text-xs text-muted-foreground">Expected profit</p>
                        <p class="mt-2 text-2xl">
                            <MoneyText
                                :amount="summary.expected_profit"
                                :currency="summary.currency"
                            />
                        </p>
                    </Card>
                    <Card>
                        <p class="text-xs text-muted-foreground">Revenue</p>
                        <p class="mt-2 text-2xl">
                            <MoneyText :amount="summary.revenue" :currency="summary.currency" />
                        </p>
                    </Card>
                    <Card>
                        <p class="text-xs text-muted-foreground">Fees</p>
                        <p class="mt-2 text-2xl">
                            <MoneyText :amount="summary.fees" :currency="summary.currency" />
                        </p>
                    </Card>
                    <Card>
                        <p class="text-xs text-muted-foreground">Incomplete orders</p>
                        <p class="mt-2 text-2xl font-semibold text-amber-700">
                            {{ summary.incomplete_orders ?? 0 }}
                        </p>
                    </Card>
                </div>

                <div>
                    <h2 class="mb-2 text-sm font-semibold">Reconciliación de caja</h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <Card>
                            <p class="text-xs text-muted-foreground">Neto esperado</p>
                            <p class="mt-2 text-xl">
                                <MoneyText :amount="summary.expected_net" :currency="summary.currency" />
                            </p>
                        </Card>
                        <Card>
                            <p class="text-xs text-muted-foreground">Asentado (settled)</p>
                            <p class="mt-2 text-xl">
                                <MoneyText :amount="summary.settled_net" :currency="summary.currency" />
                            </p>
                        </Card>
                        <Card>
                            <p class="text-xs text-muted-foreground">Liberado</p>
                            <p class="mt-2 text-xl">
                                <MoneyText :amount="summary.released_net" :currency="summary.currency" />
                            </p>
                        </Card>
                        <Card>
                            <p class="text-xs text-muted-foreground">Retirado</p>
                            <p class="mt-2 text-xl">
                                <MoneyText :amount="summary.withdrawn_net" :currency="summary.currency" />
                            </p>
                        </Card>
                        <Card>
                            <p class="text-xs text-muted-foreground">Diff (esperado − asentado)</p>
                            <p class="mt-2 text-xl font-semibold">
                                <MoneyText :amount="summary.cash_diff" :currency="summary.currency" />
                            </p>
                            <Badge class="mt-2" variant="outline">{{ summary.cash_status }}</Badge>
                        </Card>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <Card class="p-4">
                        <p class="text-xs font-semibold uppercase text-muted-foreground">Última corrida</p>
                        <template v-if="latest_run">
                            <p class="mt-2 text-sm">
                                Status: <Badge variant="outline">{{ latest_run.status }}</Badge>
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                matched {{ latest_run.orders_matched }} · unmatched orders
                                {{ latest_run.orders_unmatched }} · unmatched entries
                                {{ latest_run.entries_unmatched }}
                            </p>
                        </template>
                        <p v-else class="mt-2 text-sm text-muted-foreground">
                            Aún no hay corridas. Usa Sync reportes en Caja o
                            <code class="text-xs">finance:sync-cash-reports --all</code>.
                        </p>
                    </Card>
                    <Card class="p-4">
                        <p class="text-xs font-semibold uppercase text-muted-foreground">Capabilities cash</p>
                        <ul v-if="capabilities?.length" class="mt-2 space-y-1 text-xs">
                            <li
                                v-for="cap in capabilities"
                                :key="`${cap.connection_id}-${cap.capability_key}`"
                                class="flex justify-between gap-2"
                            >
                                <span>{{ cap.capability_key }} · conn #{{ cap.connection_id }}</span>
                                <Badge :variant="cap.enabled ? 'default' : 'outline'">
                                    {{ cap.enabled ? 'OK' : 'NO' }}
                                </Badge>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-sm text-muted-foreground">
                            Sin probe aún. Corre
                            <code class="text-xs">finance:probe-cash-apis --sync</code>.
                        </p>
                    </Card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
