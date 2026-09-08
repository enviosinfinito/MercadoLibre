<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import DataTable from '@/Components/App/DataTable.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface UsageRow {
    metric_key: string;
    quantity: number;
    period_key: string;
}

interface BillingProps {
    stripe_configured: boolean;
    customer_portal_url: string | null;
    subscription: { id: number; status: string } | null;
    usage: UsageRow[];
}

const props = defineProps<{
    billing: BillingProps;
}>();

const page = usePage();
const flash = computed(() => (page.props as { flash?: { success?: string; error?: string } }).flash);

const openPortal = () => {
    router.post(route('billing.portal'));
};
</script>

<template>
    <Head title="Billing" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Billing"
                    description="Workspace subscription and Stripe customer portal."
                />

                <p
                    v-if="flash?.success"
                    class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                >
                    {{ flash.success }}
                </p>
                <p
                    v-if="flash?.error"
                    class="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800"
                >
                    {{ flash.error }}
                </p>

                <div class="mb-6 space-y-2 rounded-lg border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium">Stripe</div>
                            <div class="text-xs text-muted-foreground">
                                {{
                                    billing.stripe_configured
                                        ? 'API keys configured'
                                        : 'Set STRIPE_KEY / STRIPE_SECRET to enable'
                                }}
                            </div>
                        </div>
                        <Badge
                            :variant="billing.stripe_configured ? 'success' : 'secondary'"
                        >
                            {{ billing.stripe_configured ? 'Ready' : 'Not configured' }}
                        </Badge>
                    </div>

                    <div class="flex items-center justify-between gap-4 pt-2">
                        <div>
                            <div class="text-sm font-medium">Subscription</div>
                            <div class="text-xs text-muted-foreground capitalize">
                                {{ billing.subscription?.status ?? 'none' }}
                            </div>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="!billing.stripe_configured"
                            @click="openPortal"
                        >
                            Open customer portal
                        </Button>
                    </div>
                </div>

                <DataTable
                    :is-empty="billing.usage.length === 0"
                    empty-title="No usage this period"
                    empty-description="Usage counters appear as the workspace consumes quotas."
                >
                    <template #head>
                        <TableHead>Metric</TableHead>
                        <TableHead>Period</TableHead>
                        <TableHead>Quantity</TableHead>
                    </template>
                    <TableRow v-for="row in billing.usage" :key="`${row.metric_key}-${row.period_key}`">
                        <TableCell class="font-mono text-xs">{{ row.metric_key }}</TableCell>
                        <TableCell>{{ row.period_key }}</TableCell>
                        <TableCell>{{ row.quantity }}</TableCell>
                    </TableRow>
                </DataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
