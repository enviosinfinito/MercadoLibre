<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import DataTable from '@/Components/App/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
    generated_at: string;
    kpis: {
        resources_affected: number;
        incidents_today: number;
        sla_30d: number;
        open_alerts: number;
        open_dead_letters: number;
    };
    integrations: Array<{
        id: number;
        provider: string;
        external_user_id: string | null;
        status: string;
        health: string;
        incidents_24h: number;
        last_synced_at: string | null;
        last_sync_run_at: string | null;
        freshness_status: string | null;
    }>;
    alerts: Array<{
        id: number;
        title: string;
        body: string | null;
        severity: string;
        status: string;
        triggered_at: string | null;
    }>;
    dead_letters: Array<{
        id: number;
        job_class: string | null;
        error_redacted: string | null;
        failed_at: string | null;
    }>;
    recent_syncs: Array<{
        id: number;
        resource_type: string | null;
        status: string;
        started_at: string | null;
        finished_at: string | null;
    }>;
    recent_outbound: Array<{
        id: number;
        command_type: string;
        status: string;
        dry_run: boolean;
        last_error_redacted: string | null;
        created_at: string | null;
    }>;
}>();

const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | null = null;
let poll: ReturnType<typeof setInterval> | null = null;

const secondsAgo = computed(() => {
    const generated = new Date(props.generated_at).getTime();
    return Math.max(0, Math.floor((now.value - generated) / 1000));
});

function healthVariant(health: string) {
    switch (health) {
        case 'Operativo':
            return 'success' as const;
        case 'Latencia':
            return 'warning' as const;
        case 'Degradado':
        case 'Error':
            return 'danger' as const;
        default:
            return 'secondary' as const;
    }
}

function acknowledge(id: number) {
    router.post(route('monitoring.alerts.acknowledge', id), {}, { preserveScroll: true });
}

onMounted(() => {
    tick = setInterval(() => {
        now.value = Date.now();
    }, 1000);
    poll = setInterval(() => {
        router.reload({ only: ['generated_at', 'kpis', 'integrations', 'alerts', 'dead_letters', 'recent_syncs', 'recent_outbound'] });
    }, 15000);
});

onUnmounted(() => {
    if (tick) clearInterval(tick);
    if (poll) clearInterval(poll);
});
</script>

<template>
    <Head title="Monitoreo" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Centro de monitoreo"
                    description="Salud de integraciones, sync y alertas en vivo."
                >
                    <template #actions>
                        <div class="flex items-center gap-2 text-sm text-slate-600">
                            <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500 animate-live-pulse" />
                            En vivo · actualizado hace {{ secondsAgo }} s
                        </div>
                    </template>
                </PageHeader>

                <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <p class="text-xs uppercase tracking-wider text-muted-foreground">Recursos afectados</p>
                        <p class="mt-2 text-3xl font-semibold">{{ kpis.resources_affected }}</p>
                    </Card>
                    <Card>
                        <p class="text-xs uppercase tracking-wider text-muted-foreground">Incidencias hoy</p>
                        <p class="mt-2 text-3xl font-semibold">{{ kpis.incidents_today }}</p>
                    </Card>
                    <Card>
                        <p class="text-xs uppercase tracking-wider text-muted-foreground">SLA 30 días</p>
                        <p class="mt-2 text-3xl font-semibold">{{ kpis.sla_30d }}%</p>
                    </Card>
                    <Card>
                        <p class="text-xs uppercase tracking-wider text-muted-foreground">Alertas abiertas</p>
                        <p class="mt-2 text-3xl font-semibold">{{ kpis.open_alerts }}</p>
                    </Card>
                </div>

                <Card class="mb-6">
                    <template #header>
                        <h3 class="text-sm font-semibold">Integraciones</h3>
                    </template>
                    <DataTable
                        :is-empty="integrations.length === 0"
                        empty-title="Sin conexiones"
                    >
                        <template #head>
                            <TableHead>Integración</TableHead>
                            <TableHead>Estado</TableHead>
                            <TableHead>Incidencias 24h</TableHead>
                            <TableHead>Última verificación</TableHead>
                        </template>
                        <TableRow
                            v-for="row in integrations"
                            :key="row.id"
                        >
                            <TableCell>
                                <div class="font-medium capitalize">{{ row.provider }}</div>
                                <div class="font-mono text-[10px] text-muted-foreground">
                                    {{ row.external_user_id }}
                                </div>
                            </TableCell>
                            <TableCell>
                                <Badge :variant="healthVariant(row.health)">
                                    {{ row.health }}
                                </Badge>
                            </TableCell>
                            <TableCell>{{ row.incidents_24h }}</TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ row.last_synced_at ?? row.last_sync_run_at ?? '—' }}
                            </TableCell>
                        </TableRow>
                    </DataTable>
                </Card>

                <div class="mb-6 grid gap-4 lg:grid-cols-2">
                    <Card>
                        <template #header>
                            <h3 class="text-sm font-semibold">Alertas</h3>
                        </template>
                        <div
                            v-if="alerts.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Sin alertas abiertas.
                        </div>
                        <ul
                            v-else
                            class="divide-y divide-slate-100"
                        >
                            <li
                                v-for="alert in alerts"
                                :key="alert.id"
                                class="flex items-start justify-between gap-3 py-3"
                            >
                                <div>
                                    <p class="text-sm font-medium text-slate-900">{{ alert.title }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ alert.body }}
                                    </p>
                                    <Badge
                                        variant="warning"
                                        class="mt-1 capitalize"
                                    >
                                        {{ alert.severity }}
                                    </Badge>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="acknowledge(alert.id)"
                                >
                                    Ack
                                </Button>
                            </li>
                        </ul>
                    </Card>

                    <Card>
                        <template #header>
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold">Dead letters</h3>
                                <Link
                                    :href="route('ops.dead-letters.index')"
                                    class="text-xs font-medium text-brand hover:underline"
                                >
                                    Ver todo
                                </Link>
                            </div>
                        </template>
                        <div
                            v-if="dead_letters.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Cola limpia.
                        </div>
                        <ul
                            v-else
                            class="divide-y divide-slate-100 text-sm"
                        >
                            <li
                                v-for="dl in dead_letters"
                                :key="dl.id"
                                class="py-2"
                            >
                                <p class="font-mono text-xs">#{{ dl.id }} · {{ dl.job_class }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ dl.error_redacted ?? '—' }}
                                </p>
                            </li>
                        </ul>
                    </Card>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <template #header>
                            <h3 class="text-sm font-semibold">Sync recientes</h3>
                        </template>
                        <ul class="divide-y divide-slate-100 text-sm">
                            <li
                                v-for="s in recent_syncs"
                                :key="s.id"
                                class="flex justify-between py-2"
                            >
                                <span>{{ s.resource_type ?? 'sync' }} · {{ s.status }}</span>
                                <span class="text-xs text-muted-foreground">{{ s.started_at }}</span>
                            </li>
                            <li
                                v-if="recent_syncs.length === 0"
                                class="py-2 text-muted-foreground"
                            >
                                Sin syncs.
                            </li>
                        </ul>
                    </Card>
                    <Card>
                        <template #header>
                            <h3 class="text-sm font-semibold">Outbound reciente</h3>
                        </template>
                        <ul class="divide-y divide-slate-100 text-sm">
                            <li
                                v-for="o in recent_outbound"
                                :key="o.id"
                                class="flex justify-between py-2"
                            >
                                <span>
                                    #{{ o.id }} {{ o.command_type }}
                                    <Badge
                                        class="ml-1"
                                        :variant="o.status === 'failed' ? 'danger' : 'secondary'"
                                    >
                                        {{ o.status }}
                                    </Badge>
                                    <span
                                        v-if="o.dry_run"
                                        class="ml-1 text-[10px] text-muted-foreground"
                                    >dry-run</span>
                                </span>
                                <span class="text-xs text-muted-foreground">{{ o.created_at }}</span>
                            </li>
                            <li
                                v-if="recent_outbound.length === 0"
                                class="py-2 text-muted-foreground"
                            >
                                Sin comandos.
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
