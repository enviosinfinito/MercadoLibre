<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status: {
        enabled: boolean;
        enabled_until: string | null;
        remaining_seconds: number;
    };
    duration_minutes: number;
}>();

const remaining = ref(props.status.remaining_seconds);

watch(
    () => props.status.remaining_seconds,
    (value) => {
        remaining.value = value;
    },
);

let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timer = setInterval(() => {
        if (remaining.value > 0) {
            remaining.value -= 1;
            if (remaining.value <= 0) {
                router.reload({ only: ['status'] });
            }
        }
    }, 1000);
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }
});

const countdown = computed(() => {
    const total = Math.max(0, remaining.value);
    const mm = String(Math.floor(total / 60)).padStart(2, '0');
    const ss = String(total % 60).padStart(2, '0');

    return `${mm}:${ss}`;
});

const form = useForm({
    enabled: false,
});

function enable() {
    form.enabled = true;
    form.post(route('admin.diagnostic-logging.update'), { preserveScroll: true });
}

function disable() {
    form.enabled = false;
    form.post(route('admin.diagnostic-logging.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Diagnostic logging" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Diagnostic logging"
                    description="Registro temporal de sync_http_logs (request/response HTTP). Apagado por defecto."
                />

                <Card class="mt-6 space-y-5 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-slate-900">Estado</p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Al activarlo, se apaga solo a los {{ duration_minutes }} minutos.
                            </p>
                        </div>
                        <Badge :variant="status.enabled && remaining > 0 ? 'success' : 'muted'">
                            {{ status.enabled && remaining > 0 ? 'Activo' : 'Apagado' }}
                        </Badge>
                    </div>

                    <div
                        v-if="status.enabled && remaining > 0"
                        class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                    >
                        Se apaga automáticamente en
                        <span class="font-mono font-semibold tabular-nums">{{ countdown }}</span>
                        <span v-if="status.enabled_until" class="mt-1 block text-xs text-amber-800/80">
                            Hasta {{ status.enabled_until }}
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="!status.enabled || remaining <= 0"
                            size="sm"
                            :disabled="form.processing"
                            @click="enable"
                        >
                            Activar {{ duration_minutes }} min
                        </Button>
                        <template v-else>
                            <Button
                                size="sm"
                                :disabled="form.processing"
                                @click="enable"
                            >
                                Renovar {{ duration_minutes }} min
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="form.processing"
                                @click="disable"
                            >
                                Apagar ahora
                            </Button>
                        </template>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Solo afecta <code class="rounded bg-slate-100 px-1">sync_http_logs</code>.
                        Snapshots, webhooks y datos de dominio siguen guardándose siempre.
                    </p>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
