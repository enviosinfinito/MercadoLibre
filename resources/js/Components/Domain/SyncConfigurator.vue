<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { router, useForm } from '@inertiajs/vue3';

export interface SyncFieldGroup {
    key: string;
    label: string;
    description: string;
    cost_hint: string;
    default_enabled: boolean;
}

export interface SyncCatalogResource {
    key: string;
    label: string;
    domain: string;
    description: string;
    cost_hint: string;
    default_enabled: boolean;
    modes: string[];
    depends_on: string[];
    field_groups: SyncFieldGroup[];
    default_config: {
        modes?: string[];
        include?: Record<string, boolean>;
        statuses?: string[];
        lookback_days?: number;
    };
}

export interface SyncProfileProp {
    resource_key: string;
    enabled: boolean;
    config: {
        include?: Record<string, boolean>;
        statuses?: string[];
        modes?: string[];
        lookback_days?: number;
    };
}

const props = withDefaults(
    defineProps<{
        connectionId: number;
        catalog: SyncCatalogResource[];
        profiles: SyncProfileProp[];
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const domainLabels: Record<string, string> = {
    catalog: 'Catálogo',
    sales: 'Ventas',
    finance: 'Finanzas',
    post_sale: 'Postventa',
    ads: 'Publicidad',
    ops: 'Operaciones',
};

type DraftProfile = {
    resource_key: string;
    enabled: boolean;
    include: Record<string, boolean>;
    lookback_days: number | null;
};

const buildDraft = (): DraftProfile[] =>
    props.catalog.map((resource) => {
        const saved = props.profiles.find((p) => p.resource_key === resource.key);
        const include: Record<string, boolean> = {};
        for (const group of resource.field_groups) {
            include[group.key] =
                saved?.config?.include?.[group.key] ?? group.default_enabled;
        }

        const defaultLookback =
            typeof resource.default_config?.lookback_days === 'number'
                ? resource.default_config.lookback_days
                : null;
        const savedLookback =
            typeof saved?.config?.lookback_days === 'number'
                ? saved.config.lookback_days
                : null;

        return {
            resource_key: resource.key,
            enabled: saved?.enabled ?? resource.default_enabled,
            include,
            lookback_days:
                resource.key === 'orders'
                    ? (savedLookback ?? defaultLookback ?? 90)
                    : null,
        };
    });

const draft = reactive<{ items: DraftProfile[] }>({ items: buildDraft() });

watch(
    () => [props.catalog, props.profiles],
    () => {
        draft.items = buildDraft();
    },
    { deep: true },
);

const grouped = computed(() => {
    const map = new Map<string, SyncCatalogResource[]>();
    for (const resource of props.catalog) {
        const list = map.get(resource.domain) ?? [];
        list.push(resource);
        map.set(resource.domain, list);
    }
    return [...map.entries()];
});

const draftFor = (key: string) =>
    draft.items.find((item) => item.resource_key === key);

const form = useForm({
    profiles: [] as Array<{
        resource_key: string;
        enabled: boolean;
        config: {
            include: Record<string, boolean>;
            lookback_days?: number;
        };
    }>,
});

const save = () => {
    form.profiles = draft.items.map((item) => {
        const config: {
            include: Record<string, boolean>;
            lookback_days?: number;
        } = { include: { ...item.include } };

        if (item.resource_key === 'orders' && item.lookback_days != null) {
            config.lookback_days = Math.max(
                1,
                Math.min(3650, Number(item.lookback_days) || 90),
            );
        }

        return {
            resource_key: item.resource_key,
            enabled: item.enabled,
            config,
        };
    });
    form.put(route('connections.sync-profiles.update', props.connectionId), {
        preserveScroll: true,
    });
};

const syncNow = (resourceKey: string) => {
    router.post(
        route('connections.sync-now', props.connectionId),
        { resource_key: resourceKey },
        { preserveScroll: true },
    );
};
</script>

<template>
    <div :class="compact ? 'space-y-3' : 'space-y-6'">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <h2
                    :class="
                        compact
                            ? 'text-[13px] font-semibold tracking-tight text-slate-900'
                            : 'text-lg font-semibold text-slate-900'
                    "
                >
                    Sync Configurator
                </h2>
                <p
                    :class="
                        compact
                            ? 'mt-0.5 text-[11px] text-muted-foreground'
                            : 'mt-1 text-sm text-muted-foreground'
                    "
                >
                    Elige qué datos traer de este marketplace. La config es por
                    conexión, no por usuario.
                </p>
            </div>
            <Button
                size="sm"
                :class="compact ? 'h-7 px-2.5 text-[11px]' : undefined"
                :disabled="form.processing"
                @click="save"
            >
                Guardar
            </Button>
        </div>

        <section
            v-for="[domain, resources] in grouped"
            :key="domain"
            :class="compact ? 'space-y-2' : 'space-y-3'"
        >
            <h3
                :class="
                    compact
                        ? 'text-[10px] font-medium uppercase tracking-wide text-muted-foreground'
                        : 'text-sm font-medium uppercase tracking-wide text-muted-foreground'
                "
            >
                {{ domainLabels[domain] ?? domain }}
            </h3>

            <Card
                v-for="resource in resources"
                :key="resource.key"
                :class="
                    compact
                        ? 'rounded-xl border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04)]'
                        : undefined
                "
                :content-class="compact ? 'p-3' : 'p-4 sm:p-5'"
            >
                <div
                    class="flex flex-col sm:flex-row sm:items-start sm:justify-between"
                    :class="compact ? 'gap-2.5' : 'gap-4'"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <label
                                class="inline-flex items-center gap-2 font-medium text-slate-900"
                                :class="compact ? 'text-[12px]' : 'text-sm'"
                            >
                                <input
                                    v-model="draftFor(resource.key)!.enabled"
                                    type="checkbox"
                                    :class="
                                        compact
                                            ? 'size-3.5 rounded border-border text-slate-900 focus:ring-slate-400'
                                            : 'size-4 rounded border-border text-slate-900 focus:ring-slate-400'
                                    "
                                />
                                {{ resource.label }}
                            </label>
                            <span
                                v-if="resource.cost_hint"
                                class="text-muted-foreground"
                                :class="compact ? 'text-[10px]' : 'text-xs'"
                            >
                                {{ resource.cost_hint }}
                            </span>
                        </div>
                        <p
                            v-if="resource.description"
                            class="text-muted-foreground"
                            :class="compact ? 'mt-0.5 text-[11px]' : 'mt-1 text-sm'"
                        >
                            {{ resource.description }}
                        </p>
                    </div>

                    <Button
                        v-if="
                            draftFor(resource.key)?.enabled &&
                            resource.modes?.includes('bootstrap')
                        "
                        size="sm"
                        variant="outline"
                        :class="compact ? 'h-7 px-2 text-[11px]' : undefined"
                        @click="syncNow(resource.key)"
                    >
                        Sincronizar ahora
                    </Button>
                </div>

                <div
                    v-if="
                        resource.key === 'orders' &&
                        draftFor(resource.key)?.lookback_days != null
                    "
                    class="flex flex-wrap items-center gap-2 border-t border-border"
                    :class="[
                        compact ? 'mt-2.5 pt-2.5' : 'mt-4 gap-3 pt-4',
                        {
                            'pointer-events-none opacity-50': !draftFor(resource.key)
                                ?.enabled,
                        },
                    ]"
                >
                    <label
                        class="font-medium text-slate-800"
                        :class="compact ? 'text-[12px]' : 'text-sm'"
                        for="orders-lookback"
                    >
                        Periodo histórico
                    </label>
                    <div class="flex items-center gap-1.5">
                        <input
                            id="orders-lookback"
                            v-model.number="draftFor(resource.key)!.lookback_days"
                            type="number"
                            min="1"
                            max="3650"
                            class="rounded-md border border-border bg-white text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-400"
                            :class="
                                compact
                                    ? 'h-8 w-20 px-2 text-xs'
                                    : 'w-24 px-2 py-1.5 text-sm'
                            "
                            :disabled="!draftFor(resource.key)?.enabled"
                        />
                        <span
                            class="text-muted-foreground"
                            :class="compact ? 'text-[11px]' : 'text-sm'"
                        >
                            días hacia atrás
                        </span>
                    </div>
                    <p
                        class="w-full text-muted-foreground"
                        :class="compact ? 'text-[10px]' : 'text-xs'"
                    >
                        Guarda la config y luego pulsa “Sincronizar ahora”.
                    </p>
                </div>

                <div
                    v-if="resource.field_groups.length"
                    class="grid border-t border-border sm:grid-cols-2"
                    :class="[
                        compact ? 'mt-2.5 gap-1.5 pt-2.5' : 'mt-4 gap-3 pt-4',
                        {
                            'pointer-events-none opacity-50': !draftFor(resource.key)
                                ?.enabled,
                        },
                    ]"
                >
                    <label
                        v-for="group in resource.field_groups"
                        :key="group.key"
                        class="flex items-start gap-2 rounded-md border border-transparent hover:border-border"
                        :class="compact ? 'p-1.5' : 'p-2'"
                    >
                        <input
                            v-model="draftFor(resource.key)!.include[group.key]"
                            type="checkbox"
                            class="mt-0.5 rounded border-border text-slate-900 focus:ring-slate-400"
                            :class="compact ? 'size-3.5' : 'size-4'"
                            :disabled="!draftFor(resource.key)?.enabled"
                        />
                        <span class="min-w-0">
                            <span
                                class="block font-medium text-slate-800"
                                :class="compact ? 'text-[12px]' : 'text-sm'"
                            >
                                {{ group.label }}
                            </span>
                            <span
                                v-if="group.description"
                                class="block text-muted-foreground"
                                :class="compact ? 'text-[10px]' : 'text-xs'"
                            >
                                {{ group.description }}
                            </span>
                            <span
                                v-if="group.cost_hint"
                                class="mt-0.5 block text-amber-700/80"
                                :class="compact ? 'text-[10px]' : 'text-xs'"
                            >
                                {{ group.cost_hint }}
                            </span>
                        </span>
                    </label>
                </div>
            </Card>
        </section>
    </div>
</template>
