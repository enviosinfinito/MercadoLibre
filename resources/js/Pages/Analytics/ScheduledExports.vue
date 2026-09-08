<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, useForm, router } from '@inertiajs/vue3';

interface ScheduleRow {
    id: number;
    name: string;
    report_type: string;
    cron_expression: string;
    format: string;
    is_active: boolean;
    next_run_at: string | null;
    last_run_at: string | null;
}

interface ReportOption {
    id: number;
    name: string;
}

defineProps<{
    schedules: { data: ScheduleRow[] };
    reports: ReportOption[];
}>();

const form = useForm({
    name: '',
    report_type: 'analytics_report',
    analytics_report_id: null as number | null,
    format: 'csv',
    cron_expression: '0 8 * * *',
    timezone: 'America/Mexico_City',
    delivery: { emails: [''] as string[] },
});

const submit = () => {
    form.delivery.emails = form.delivery.emails.filter((e) => e.trim() !== '');
    form.post(route('analytics.schedules.store'), {
        onSuccess: () => form.reset('name'),
    });
};

const toggle = (row: ScheduleRow) => {
    router.put(route('analytics.schedules.update', row.id), {
        is_active: !row.is_active,
    });
};

const remove = (row: ScheduleRow) => {
    router.delete(route('analytics.schedules.destroy', row.id));
};
</script>

<template>
    <Head title="Scheduled exports" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Scheduled exports"
                    description="Envía reportes Analytics por email en horarios definidos."
                />

                <Card class="mb-6 p-4">
                    <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="submit">
                        <Input v-model="form.name" placeholder="Nombre" required />
                        <select
                            v-model="form.analytics_report_id"
                            class="h-10 rounded-md border px-3 text-sm"
                        >
                            <option :value="null">Query libre (report_type)</option>
                            <option v-for="r in reports" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <select v-model="form.cron_expression" class="h-10 rounded-md border px-3 text-sm">
                            <option value="0 * * * *">Cada hora</option>
                            <option value="0 8 * * *">Diario 08:00</option>
                            <option value="0 8 * * 1">Lunes 08:00</option>
                        </select>
                        <Input v-model="form.delivery.emails[0]" type="email" placeholder="Email destino" />
                        <select v-model="form.format" class="h-10 rounded-md border px-3 text-sm">
                            <option value="csv">CSV</option>
                            <option value="xlsx">XLSX</option>
                        </select>
                        <Button type="submit" size="sm" :disabled="form.processing">Crear schedule</Button>
                    </form>
                </Card>

                <Card class="overflow-hidden">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2">Nombre</th>
                                <th class="px-3 py-2">Cron</th>
                                <th class="px-3 py-2">Next</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in schedules.data" :key="row.id" class="border-t">
                                <td class="px-3 py-2 font-medium">{{ row.name }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ row.cron_expression }}</td>
                                <td class="px-3 py-2 text-xs">{{ row.next_run_at || '—' }}</td>
                                <td class="px-3 py-2">
                                    <Badge :variant="row.is_active ? 'success' : 'secondary'">
                                        {{ row.is_active ? 'active' : 'paused' }}
                                    </Badge>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <Button size="sm" variant="outline" class="mr-2" @click="toggle(row)">
                                        Toggle
                                    </Button>
                                    <Button size="sm" variant="outline" @click="remove(row)">Delete</Button>
                                </td>
                            </tr>
                            <tr v-if="!schedules.data.length">
                                <td colspan="5" class="px-3 py-8 text-center text-muted-foreground">
                                    Sin schedules.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
