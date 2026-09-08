<script setup lang="ts">
import { computed } from 'vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { router, useForm } from '@inertiajs/vue3';

export interface ConnectionInviteRow {
    id: number;
    provider: string;
    url: string;
    status: 'active' | 'used' | 'expired' | 'revoked';
    expires_at: string | null;
    used_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
}

const props = defineProps<{
    invites: ConnectionInviteRow[];
}>();

const form = useForm({
    provider: 'mercadolibre',
});

const copiedId = defineModel<number | null>('copiedId', { default: null });

const statusLabel = (status: ConnectionInviteRow['status']) => {
    switch (status) {
        case 'active':
            return 'Activo';
        case 'used':
            return 'Usado';
        case 'expired':
            return 'Expirado';
        case 'revoked':
            return 'Revocado';
        default:
            return status;
    }
};

const statusTone = (status: ConnectionInviteRow['status']) => {
    switch (status) {
        case 'active':
            return 'success' as const;
        case 'used':
            return 'secondary' as const;
        default:
            return 'danger' as const;
    }
};

const providerLabel = (provider: string) =>
    provider === 'mercadolibre' ? 'Mercado Libre' : provider;

const formatDate = (value: string | null) => {
    if (!value) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat('es-MX', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value));
    } catch {
        return value;
    }
};

const createInvite = () => {
    form.post(route('connections.invites.store'), {
        preserveScroll: true,
    });
};

const copyUrl = async (invite: ConnectionInviteRow) => {
    try {
        await navigator.clipboard.writeText(invite.url);
        copiedId.value = invite.id;
        window.setTimeout(() => {
            if (copiedId.value === invite.id) {
                copiedId.value = null;
            }
        }, 2000);
    } catch {
        window.prompt('Copia este enlace:', invite.url);
    }
};

const revoke = (id: number) => {
    if (!confirm('¿Revocar este enlace? Ya no podrá usarse.')) {
        return;
    }

    router.delete(route('connections.invites.destroy', id), {
        preserveScroll: true,
    });
};

const hasInvites = computed(() => props.invites.length > 0);
</script>

<template>
    <section
        class="rounded-xl border border-slate-200/70 bg-white p-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)] sm:p-4"
    >
        <div class="flex flex-col gap-2.5 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h2 class="text-[13px] font-semibold tracking-tight text-slate-900">
                    Solicitar conexión por enlace
                </h2>
                <p class="mt-0.5 max-w-xl text-[11px] text-muted-foreground">
                    Genera un enlace único para que alguien autorice Mercado Libre sin iniciar
                    sesión en la plataforma. El enlace es de un solo uso y expira en 7 días.
                </p>
            </div>
            <Button
                size="sm"
                class="h-7 shrink-0 px-2.5 text-[11px]"
                :disabled="form.processing"
                @click="createInvite"
            >
                Generar enlace
            </Button>
        </div>

        <div
            v-if="hasInvites"
            class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200/70"
        >
            <div
                v-for="invite in invites"
                :key="invite.id"
                class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0 space-y-0.5">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-[12px] font-medium tracking-tight text-slate-900">
                            {{ providerLabel(invite.provider) }}
                        </span>
                        <Badge
                            :variant="statusTone(invite.status)"
                            class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
                        >
                            {{ statusLabel(invite.status) }}
                        </Badge>
                    </div>
                    <p
                        class="truncate font-mono text-[10px] text-muted-foreground"
                        :title="invite.url"
                    >
                        {{ invite.url }}
                    </p>
                    <p class="text-[10px] text-muted-foreground">
                        Creado {{ formatDate(invite.created_at) }}
                        <span v-if="invite.status === 'active'">
                            · Expira {{ formatDate(invite.expires_at) }}
                        </span>
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap gap-1.5">
                    <Button
                        v-if="invite.status === 'active'"
                        variant="outline"
                        size="sm"
                        class="h-7 px-2 text-[11px]"
                        @click="copyUrl(invite)"
                    >
                        {{ copiedId === invite.id ? 'Copiado' : 'Copiar' }}
                    </Button>
                    <Button
                        v-if="invite.status === 'active'"
                        variant="ghost"
                        size="sm"
                        class="h-7 px-2 text-[11px] text-red-700 hover:text-red-800"
                        @click="revoke(invite.id)"
                    >
                        Revocar
                    </Button>
                </div>
            </div>
        </div>

        <p v-else class="mt-2.5 text-[11px] text-muted-foreground">
            Aún no hay enlaces. Genera uno y compártelo con quien deba autorizar la cuenta.
        </p>
    </section>
</template>
