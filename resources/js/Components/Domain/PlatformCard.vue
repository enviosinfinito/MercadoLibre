<script setup lang="ts">
import { computed, type Component } from 'vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import ConnectionHealth from '@/Components/Domain/ConnectionHealth.vue';
import MercadoLibreLogo from '@/Components/Domain/PlatformLogos/MercadoLibreLogo.vue';
import AmazonLogo from '@/Components/Domain/PlatformLogos/AmazonLogo.vue';
import EcartLogo from '@/Components/Domain/PlatformLogos/EcartLogo.vue';
import { router } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

export interface PlatformConnection {
    id: number;
    provider: string;
    external_user_id: string | null;
    site_id: string | null;
    display_name?: string | null;
    permalink?: string | null;
    avatar_url?: string | null;
    reputation_level?: string | null;
    power_seller_status?: string | null;
    color?: string | null;
    status: string;
    needs_reauthorization: boolean;
    last_synced_at: string | null;
    freshness_status?: string | null;
}

export interface PlatformDef {
    id: string;
    name: string;
    group: string;
    status: 'available' | 'coming_soon';
    setup_label: string;
    connect_route: string | null;
}

const props = defineProps<{
    platform: PlatformDef;
    connections: PlatformConnection[];
}>();

const emit = defineEmits<{
    disconnect: [id: number];
}>();

const logos: Record<string, Component> = {
    mercadolibre: MercadoLibreLogo,
    amazon: AmazonLogo,
    ecart: EcartLogo,
};

const Logo = computed(() => logos[props.platform.id] ?? null);

const isComingSoon = computed(() => props.platform.status === 'coming_soon');
const hasConnections = computed(() => props.connections.length > 0);
const connectHref = computed(() =>
    props.platform.connect_route ? route(props.platform.connect_route) : undefined,
);

const needsReauth = computed(() =>
    props.connections.some((c) => c.needs_reauthorization),
);

const canSyncListings = (c: PlatformConnection) =>
    props.platform.id === 'mercadolibre' &&
    !c.needs_reauthorization &&
    ['active', 'connected'].includes(c.status.toLowerCase());

const syncListings = (id: number) => {
    router.post(route('connections.sync-listings', id));
};

const accountTitle = (c: PlatformConnection) =>
    c.display_name?.trim() || c.external_user_id || '—';

const initials = (c: PlatformConnection) => {
    const label = accountTitle(c);
    const parts = label.replace(/[^a-zA-Z0-9]+/g, ' ').trim().split(/\s+/);
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return label.slice(0, 2).toUpperCase();
};

const reputationBadgeVariant = (level: string | null | undefined) => {
    if (!level) return 'muted' as const;
    if (level.includes('green')) return 'success' as const;
    if (level.includes('yellow') || level.includes('orange')) return 'warning' as const;
    if (level.includes('red')) return 'danger' as const;
    return 'muted' as const;
};

const reputationLabel = (level: string | null | undefined) => {
    if (!level) return null;
    const map: Record<string, string> = {
        '5_green': 'Verde',
        '4_light_green': 'Verde claro',
        '3_yellow': 'Amarillo',
        '2_orange': 'Naranja',
        '1_red': 'Rojo',
    };
    return map[level] ?? level;
};

const powerSellerLabel = (status: string | null | undefined) => {
    if (!status) return null;
    return `Líder ${status.charAt(0).toUpperCase()}${status.slice(1)}`;
};
</script>

<template>
    <article
        :class="
            cn(
                'group flex flex-col rounded-xl border border-border bg-card p-6 shadow-sm transition-all',
                !isComingSoon && 'hover:border-brand/30 hover:shadow-md',
                isComingSoon && 'opacity-75',
            )
        "
    >
        <div class="flex flex-1 flex-col items-center text-center">
            <div class="mb-4 h-14 w-14">
                <component :is="Logo" v-if="Logo" class="h-full w-full" />
                <div
                    v-else
                    class="flex h-full w-full items-center justify-center rounded-xl bg-secondary text-lg font-semibold text-muted-foreground"
                >
                    {{ platform.name.charAt(0) }}
                </div>
            </div>

            <h3 class="text-base font-semibold text-foreground">{{ platform.name }}</h3>
            <p class="mt-1 text-xs text-muted-foreground">{{ platform.setup_label }}</p>

            <div v-if="isComingSoon" class="mt-3">
                <Badge variant="muted">Próximamente</Badge>
            </div>

            <div v-else-if="hasConnections" class="mt-4 w-full space-y-3">
                <div
                    v-for="c in connections"
                    :key="c.id"
                    class="rounded-lg border border-border bg-slate-50 px-3 py-2.5 text-left"
                >
                    <ConnectionHealth
                        :status="c.status"
                        :freshness-status="c.freshness_status"
                        :needs-reauthorization="c.needs_reauthorization"
                        :last-synced-at="c.last_synced_at"
                    />
                    <div class="mt-2 flex items-start gap-2">
                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-secondary text-[10px] font-semibold text-muted-foreground"
                        >
                            <img
                                v-if="c.avatar_url"
                                :src="c.avatar_url"
                                :alt="accountTitle(c)"
                                class="h-full w-full object-cover"
                                referrerpolicy="no-referrer"
                                loading="lazy"
                            />
                            <span v-else>{{ initials(c) }}</span>
                        </div>
                        <div class="min-w-0">
                            <p
                                v-if="c.display_name"
                                class="truncate text-xs font-medium text-foreground"
                            >
                                {{ c.display_name }}
                            </p>
                            <p
                                v-if="c.external_user_id || c.site_id"
                                class="mt-0.5 truncate font-mono text-[11px] text-muted-foreground"
                            >
                                <span v-if="c.external_user_id">{{ c.external_user_id }}</span>
                                <span v-if="c.external_user_id && c.site_id"> · </span>
                                <span v-if="c.site_id">{{ c.site_id }}</span>
                            </p>
                            <div
                                v-if="c.power_seller_status || c.reputation_level"
                                class="mt-1 flex flex-wrap gap-1"
                            >
                                <Badge
                                    v-if="c.power_seller_status"
                                    variant="secondary"
                                    class="text-[10px] font-medium"
                                >
                                    {{ powerSellerLabel(c.power_seller_status) }}
                                </Badge>
                                <Badge
                                    v-if="c.reputation_level"
                                    :variant="reputationBadgeVariant(c.reputation_level)"
                                    class="text-[10px] font-medium"
                                >
                                    {{ reputationLabel(c.reputation_level) }}
                                </Badge>
                            </div>
                            <a
                                v-if="c.permalink"
                                :href="c.permalink"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-1 inline-block text-[11px] text-brand hover:underline"
                            >
                                Ver perfil
                            </a>
                        </div>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Button
                            v-if="canSyncListings(c)"
                            size="sm"
                            variant="outline"
                            class="flex-1"
                            @click="syncListings(c.id)"
                        >
                            Sincronizar publicaciones
                        </Button>
                        <Button
                            v-if="c.needs_reauthorization && connectHref"
                            as="a"
                            :href="connectHref"
                            size="sm"
                            class="flex-1"
                        >
                            Reautorizar
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="flex-1 text-destructive hover:text-destructive"
                            @click="emit('disconnect', c.id)"
                        >
                            Desconectar
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="!isComingSoon && connectHref" class="mt-5">
            <Button
                v-if="!hasConnections"
                as="a"
                :href="connectHref"
                class="w-full"
            >
                Conectar
            </Button>
            <Button
                v-else-if="!needsReauth"
                as="a"
                :href="connectHref"
                variant="outline"
                class="w-full"
            >
                Conectar otra cuenta
            </Button>
        </div>
    </article>
</template>
