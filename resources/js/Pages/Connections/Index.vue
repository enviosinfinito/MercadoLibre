<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import ConnectionSteps from '@/Components/Domain/ConnectionSteps.vue';
import ConnectionsList from '@/Components/Domain/ConnectionsList.vue';
import ConnectionInvitePanel from '@/Components/Domain/ConnectionInvitePanel.vue';
import type { ConnectionInviteRow } from '@/Components/Domain/ConnectionInvitePanel.vue';
import PlatformCatalog from '@/Components/Domain/PlatformCatalog.vue';
import type {
    PlatformConnection,
    PlatformDef,
} from '@/Components/Domain/PlatformCard.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, router, usePage } from '@inertiajs/vue3';

const ConnectionDetailSlideOver = defineAsyncComponent(
    () => import('@/Components/Connections/ConnectionDetailSlideOver.vue'),
);

const props = defineProps<{
    connections: PlatformConnection[];
    platforms: PlatformDef[];
    invites: ConnectionInviteRow[];
}>();

const page = usePage();
const banner = ref<{ tone: 'success' | 'error'; text: string } | null>(null);
const showAdd = ref(false);
const copiedInviteId = ref<number | null>(null);
const detailOpen = ref(false);
const selectedConnectionId = ref<number | null>(null);

const hasConnections = computed(() => props.connections.length > 0);
const showWizard = computed(() => !hasConnections.value || showAdd.value);
const isAdding = computed(() => hasConnections.value && showAdd.value);

const currentStep = computed((): 1 | 2 | 3 => {
    if (props.connections.length === 0) {
        return 1;
    }

    const needsAttention = props.connections.some(
        (c) =>
            c.needs_reauthorization ||
            ['pending', 'error', 'disconnected'].includes(c.status.toLowerCase()),
    );

    return needsAttention ? 2 : 3;
});

const disconnect = (id: number) => {
    if (confirm('¿Desconectar esta cuenta?')) {
        router.delete(route('connections.destroy', id));
    }
};

const openAdd = () => {
    showAdd.value = true;
};

const backToList = () => {
    showAdd.value = false;
};

const openDetail = (id: number) => {
    selectedConnectionId.value = id;
    detailOpen.value = true;
};

const closeDetail = () => {
    detailOpen.value = false;
    selectedConnectionId.value = null;
};

onMounted(async () => {
    const params = new URLSearchParams(window.location.search);
    const meli = params.get('meli');
    const flash = page.props.flash as
        | { success?: string; connection_invite_url?: string }
        | undefined;
    const flashSuccess = flash?.success;
    const inviteUrl = flash?.connection_invite_url;

    if (params.get('add') === '1' && hasConnections.value) {
        showAdd.value = true;
    }

    const openId = params.get('connection');
    if (openId && hasConnections.value) {
        const id = Number(openId);
        if (!Number.isNaN(id)) {
            openDetail(id);
        }
    }

    if (meli === 'connected') {
        banner.value = {
            tone: 'success',
            text: 'Mercado Libre conectado correctamente.',
        };
        showAdd.value = false;
    } else if (meli === 'error') {
        const reason = params.get('reason') ?? 'unknown';
        banner.value = {
            tone: 'error',
            text: `No se pudo conectar Mercado Libre (${reason}). Intenta de nuevo.`,
        };
    } else if (flashSuccess) {
        banner.value = {
            tone: 'success',
            text: flashSuccess,
        };
    }

    if (inviteUrl && typeof inviteUrl === 'string') {
        try {
            await navigator.clipboard.writeText(inviteUrl);
            const match = props.invites.find((i) => i.url === inviteUrl);
            copiedInviteId.value = match?.id ?? null;
            banner.value = {
                tone: 'success',
                text: 'Enlace generado y copiado al portapapeles.',
            };
        } catch {
            banner.value = {
                tone: 'success',
                text: 'Enlace generado. Cópialo desde la lista de abajo.',
            };
        }
    }

    if (meli || params.get('add') === '1' || params.get('connection')) {
        router.get(
            route('connections.index'),
            {},
            { replace: true, preserveState: true, preserveScroll: true },
        );
    }
});
</script>

<template>
    <Head title="Conexiones" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="w-full space-y-4 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="banner"
                    class="rounded-xl border px-3 py-2 text-xs"
                    :class="
                        banner.tone === 'success'
                            ? 'border-emerald-200/80 bg-emerald-50 text-emerald-900'
                            : 'border-red-200/80 bg-red-50 text-red-900'
                    "
                >
                    {{ banner.text }}
                </div>

                <template v-if="showWizard">
                    <template v-if="isAdding">
                        <PageHeader
                            compact
                            title="Agregar conexión"
                            description="Elige una plataforma para vincular otra cuenta."
                        >
                            <template #actions>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-7 px-2.5 text-[11px]"
                                    @click="backToList"
                                >
                                    Volver al listado
                                </Button>
                            </template>
                        </PageHeader>
                    </template>
                    <template v-else>
                        <header class="mb-4 text-center">
                            <h1 class="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">
                                Conecta tus marketplaces
                            </h1>
                            <p class="mx-auto mt-1.5 max-w-xl text-xs text-muted-foreground sm:text-[13px]">
                                Vincula tus cuentas de e-commerce y gestiona pedidos y publicaciones desde
                                un solo lugar.
                            </p>
                        </header>

                        <div
                            class="mb-4 rounded-xl border border-slate-200/70 bg-white px-3 py-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)] sm:px-5"
                        >
                            <ConnectionSteps :current-step="currentStep" />
                        </div>
                    </template>

                    <PlatformCatalog
                        :platforms="platforms"
                        :connections="connections"
                        @disconnect="disconnect"
                    />
                </template>

                <template v-else>
                    <PageHeader
                        compact
                        title="Conexiones"
                        description="Cuentas de marketplace vinculadas a este workspace."
                    >
                        <template #actions>
                            <ExportToolbarButton
                                target-module="connections"
                                :get-payload="() => ({
                                    selectionMode: 'filter',
                                    filters: exportFiltersFromUrl(),
                                    filteredTotalHint: null,
                                })"
                            />
                            <Button
                                size="sm"
                                class="h-7 px-2.5 text-[11px]"
                                @click="openAdd"
                            >
                                Agregar conexión
                            </Button>
                        </template>
                    </PageHeader>

                    <ConnectionsList
                        :connections="connections"
                        :platforms="platforms"
                        @disconnect="disconnect"
                        @open-detail="openDetail"
                    />
                </template>

                <ConnectionInvitePanel
                    v-model:copied-id="copiedInviteId"
                    :invites="invites"
                />
            </div>
        </div>

        <ConnectionDetailSlideOver
            :show="detailOpen"
            :connection-id="selectedConnectionId ?? undefined"
            @close="closeDetail"
        />
    </AuthenticatedLayout>
</template>
