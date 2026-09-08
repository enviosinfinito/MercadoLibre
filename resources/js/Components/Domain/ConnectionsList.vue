<script setup lang="ts">
import { computed } from 'vue';
import DataTable from '@/Components/App/DataTable.vue';
import StackedData from '@/Components/App/StackedData.vue';
import ConnectionHealth from '@/Components/Domain/ConnectionHealth.vue';
import type {
    PlatformConnection,
    PlatformDef,
} from '@/Components/Domain/PlatformCard.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import { toTitleCase } from '@/lib/formatDisplayText';
import {
    connectionSurfaceStyle,
    resolveConnectionColor,
} from '@/lib/connectionColor';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
    connections: PlatformConnection[];
    platforms: PlatformDef[];
}>();

const emit = defineEmits<{
    disconnect: [id: number];
    openDetail: [id: number];
}>();

const platformById = computed(() => {
    const map = new Map<string, PlatformDef>();
    for (const platform of props.platforms) {
        map.set(platform.id, platform);
    }
    return map;
});

const platformName = (provider: string) =>
    platformById.value.get(provider)?.name ?? provider;

const connectHref = (provider: string) => {
    const routeName = platformById.value.get(provider)?.connect_route;
    return routeName ? route(routeName) : undefined;
};

const accountTitle = (c: PlatformConnection) =>
    c.display_name?.trim() || c.external_user_id || '—';

const accountStacked = (c: PlatformConnection) => {
    const name = c.display_name?.trim() || '';
    const externalId = c.external_user_id?.trim() || '';
    const siteId = c.site_id?.trim() || '';

    if (name) {
        const secondaryParts = [externalId, siteId].filter(Boolean);
        return {
            primary: name,
            secondary: secondaryParts.length ? secondaryParts.join(' · ') : null,
            primaryKind: 'name',
            secondaryKind: 'code',
        };
    }

    if (externalId) {
        return {
            primary: externalId,
            secondary: siteId || null,
            primaryKind: 'code',
            secondaryKind: 'code',
        };
    }

    return null;
};

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
    const pretty = toTitleCase(status);
    return `Líder ${pretty}`;
};

const canSyncListings = (c: PlatformConnection) =>
    c.provider === 'mercadolibre' &&
    !c.needs_reauthorization &&
    ['active', 'connected'].includes(c.status.toLowerCase());

const syncListings = (id: number) => {
    router.post(route('connections.sync-listings', id));
};
</script>

<template>
    <DataTable
        compact
        :is-empty="connections.length === 0"
        empty-title="Sin conexiones"
        empty-description="Vincula una cuenta de marketplace para empezar."
    >
        <template #head>
            <TableHead>Plataforma</TableHead>
            <TableHead>Cuenta</TableHead>
            <TableHead>Estado</TableHead>
            <TableHead class="text-right">Acciones</TableHead>
        </template>
        <TableRow
            v-for="c in connections"
            :key="c.id"
            class="conn-row cursor-pointer"
            :style="connectionSurfaceStyle(c.color)"
            @click="emit('openDetail', c.id)"
        >
            <TableCell>
                <span
                    class="inline-flex h-5 items-center gap-1 rounded-full bg-slate-100 px-1.5 text-[10px] font-medium text-slate-600"
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: resolveConnectionColor(c.color) }"
                        aria-hidden="true"
                    />
                    {{ platformName(c.provider) }}
                </span>
            </TableCell>
            <TableCell>
                <div class="flex min-w-0 items-start gap-2">
                    <div class="relative shrink-0">
                        <div
                            class="flex size-7 items-center justify-center overflow-hidden rounded-full bg-secondary text-[9px] font-semibold text-muted-foreground"
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
                        <span
                            class="absolute -bottom-0.5 -right-0.5 size-2 rounded-full border-2 border-white"
                            :style="{
                                backgroundColor: resolveConnectionColor(c.color),
                            }"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0">
                        <StackedData
                            v-if="accountStacked(c)"
                            v-bind="accountStacked(c)!"
                        />
                        <span v-else class="text-[12px] text-muted-foreground">—</span>
                        <div
                            v-if="c.power_seller_status || c.reputation_level"
                            class="mt-1 flex flex-wrap gap-1"
                        >
                            <Badge
                                v-if="c.power_seller_status"
                                variant="secondary"
                                class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
                            >
                                {{ powerSellerLabel(c.power_seller_status) }}
                            </Badge>
                            <Badge
                                v-if="c.reputation_level"
                                :variant="reputationBadgeVariant(c.reputation_level)"
                                class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium"
                            >
                                {{ reputationLabel(c.reputation_level) }}
                            </Badge>
                        </div>
                        <a
                            v-if="c.permalink"
                            :href="c.permalink"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-0.5 inline-block text-[10px] font-medium text-brand hover:underline"
                            @click.stop
                        >
                            Ver perfil
                        </a>
                    </div>
                </div>
            </TableCell>
            <TableCell @click.stop>
                <ConnectionHealth
                    :status="c.status"
                    :freshness-status="c.freshness_status"
                    :needs-reauthorization="c.needs_reauthorization"
                    :last-synced-at="c.last_synced_at"
                />
            </TableCell>
            <TableCell class="text-right" @click.stop>
                <div class="flex flex-wrap items-center justify-end gap-1.5">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="h-7 px-2 text-[11px]"
                        @click="emit('openDetail', c.id)"
                    >
                        Configurar
                    </Button>
                    <Button
                        v-if="canSyncListings(c)"
                        size="sm"
                        variant="outline"
                        class="h-7 px-2 text-[11px]"
                        @click="syncListings(c.id)"
                    >
                        Sync pubs
                    </Button>
                    <Button
                        v-if="c.needs_reauthorization && connectHref(c.provider)"
                        as="a"
                        :href="connectHref(c.provider)"
                        size="sm"
                        class="h-7 px-2 text-[11px]"
                    >
                        Reautorizar
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-7 px-2 text-[11px] text-destructive hover:text-destructive"
                        @click="emit('disconnect', c.id)"
                    >
                        Desconectar
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    </DataTable>
</template>
