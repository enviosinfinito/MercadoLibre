<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { exportFiltersFromUrl } from '@/composables/exportFiltersFromUrl';
import InfiniteListSummary from '@/Components/App/InfiniteListSummary.vue';
import SyncHttpLogSlideOver from '@/Components/Domain/SyncHttpLogSlideOver.vue';
import SyncHttpLogsSearchAndFilters from '@/Components/SyncHttpLogs/SearchAndFilters.vue';
import LogsList from '@/Components/SyncHttpLogs/LogsList.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useInfiniteList } from '@/composables/useInfiniteList';
import { filterLogs } from '@/composables/useSyncHttpLogs';
import {
    buildSyncHttpLogsListUpdatesUrl,
    getSyncHttpLogsListScrollEl,
    scrollSyncHttpLogsListToTop,
    useSyncHttpLogsListLiveUpdates,
} from '@/composables/useSyncHttpLogsListLiveUpdates';
import type { PageProps } from '@/types';

type LogRow = {
    id: number;
    created_at: string | null;
    direction: string;
    provider: string | null;
    method: string | null;
    url: string | null;
    endpoint_group: string | null;
    webhook_topic: string | null;
    webhook_topic_label: string | null;
    response_status: number | null;
    latency_ms: number | null;
    correlation_id: string | null;
    sync_run_id: number | null;
    connection: {
        id: number;
        provider: string;
        external_user_id: string | null;
    } | null;
};

const props = defineProps<{
    logs: {
        data: LogRow[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        current_page?: number;
        last_page?: number;
        total?: number;
        from?: number | null;
        to?: number | null;
        next_page_url?: string | null;
        path?: string;
        per_page?: number;
    };
    filters: {
        http_direction: string | null;
        status: string | null;
        topic: string | null;
        q: string | null;
        connection_id: number | null;
        from: string | null;
        to: string | null;
        orphan: string | null;
        body_search: string | null;
    };
    connections: Array<{
        id: number;
        provider: string;
        external_user_id: string | null;
    }>;
    webhook_topics: Array<{
        value: string;
        label: string;
    }>;
    diagnostic_logging: {
        enabled: boolean;
        enabled_until: string | null;
        remaining_seconds: number;
    };
}>();

const page = usePage<PageProps>();
const isPlatformAdmin = computed(() => Boolean(page.props.auth?.user?.is_platform_admin));
const loggingPaused = computed(
    () => !props.diagnostic_logging?.enabled || (props.diagnostic_logging?.remaining_seconds ?? 0) <= 0,
);

const detailId = ref<number | null>(null);
const showDetail = ref(false);
const searchQuery = ref('');

const {
    displayItems: baseDisplayItems,
    hasMorePages,
    totalCount,
    paginationFrom,
    paginationTo,
    isLoading,
    isLoadingMore,
    lastLoadedPage,
    loadMoreSentinel,
    resetAccumulation,
} = useInfiniteList({
    initialPaginator: computed(() => props.logs),
});

const livePrependedItems = ref<LogRow[]>([]);
const pendingNewItems = ref<LogRow[]>([]);
const pendingNewCount = ref(0);
const liveTotalBoost = ref(0);
const sinceId = ref(0);
const liveNewItemIds = ref<Record<number, boolean>>({});

function getBaseDisplayItems(): LogRow[] {
    return (baseDisplayItems.value as LogRow[]) ?? [];
}

function sortLogsNewestFirst(rows: LogRow[]): LogRow[] {
    return [...rows].sort(
        (a, b) => parseInt(String(b.id), 10) - parseInt(String(a.id), 10),
    );
}

const logsDisplayItems = computed(() => {
    const base = getBaseDisplayItems();
    const baseIds = new Set(base.map((s) => parseInt(String(s.id), 10)));
    const prepended = livePrependedItems.value.filter(
        (s) => !baseIds.has(parseInt(String(s.id), 10)),
    );
    return sortLogsNewestFirst([...prepended, ...base]);
});

const visibleLogs = computed(() =>
    filterLogs(logsDisplayItems.value, searchQuery.value),
);

const totalShown = computed(
    () => Number(totalCount.value || 0) + Number(liveTotalBoost.value || 0),
);

const showingCount = computed(() => visibleLogs.value.length);

const hasLoadedBeyondFirstPage = computed(() => {
    const loaded = lastLoadedPage.value ?? props.logs?.current_page ?? 1;
    return Number(loaded) > 1;
});

const liveUpdatesEnabled = computed(() => true);

const hasActiveUrlFilters = computed(() => {
    const f = props.filters;
    return !!(
        f.http_direction ||
        f.status ||
        f.topic ||
        f.q ||
        f.connection_id ||
        f.from ||
        f.to ||
        f.orphan ||
        f.body_search
    );
});

function recomputeSinceId() {
    let max = 0;
    for (const s of [...livePrependedItems.value, ...getBaseDisplayItems()]) {
        const id = parseInt(String(s.id), 10);
        if (Number.isFinite(id) && id > max) max = id;
    }
    sinceId.value = max;
}

function bumpSinceIdFromItems(items: LogRow[]) {
    let max = sinceId.value;
    for (const s of items) {
        const id = parseInt(String(s.id), 10);
        if (Number.isFinite(id) && id > max) max = id;
    }
    sinceId.value = max;
}

function filterFreshItems(items: LogRow[]) {
    const existingIds = new Set(
        [
            ...getBaseDisplayItems(),
            ...livePrependedItems.value,
            ...pendingNewItems.value,
        ].map((s) => parseInt(String(s.id), 10)),
    );
    return items.filter((s) => !existingIds.has(parseInt(String(s.id), 10)));
}

function markLiveNewItems(items: LogRow[]) {
    if (!items?.length) return;
    const next = { ...liveNewItemIds.value };
    for (const s of items) next[s.id] = true;
    liveNewItemIds.value = next;
    const ids = items.map((s) => s.id);
    setTimeout(() => {
        const cleaned = { ...liveNewItemIds.value };
        for (const id of ids) delete cleaned[id];
        liveNewItemIds.value = cleaned;
    }, 4000);
}

function handleLivePrepend(items: LogRow[]) {
    const fresh = filterFreshItems(items);
    if (!fresh.length) return;
    livePrependedItems.value = [...fresh, ...livePrependedItems.value];
    liveTotalBoost.value += fresh.length;
    markLiveNewItems(fresh);
    bumpSinceIdFromItems(fresh);
}

function handleLivePending(items: LogRow[]) {
    const fresh = filterFreshItems(items);
    if (!fresh.length) return;
    pendingNewItems.value = [...fresh, ...pendingNewItems.value];
    pendingNewCount.value = pendingNewItems.value.length;
    bumpSinceIdFromItems(fresh);
}

function mergePendingLiveUpdates() {
    if (!pendingNewItems.value.length) return;
    const pending = pendingNewItems.value;
    livePrependedItems.value = [...pending, ...livePrependedItems.value];
    liveTotalBoost.value += pending.length;
    markLiveNewItems(pending);
    pendingNewItems.value = [];
    pendingNewCount.value = 0;
    scrollSyncHttpLogsListToTop(getSyncHttpLogsListScrollEl(loadMoreSentinel.value));
}

function clearLiveUpdates() {
    livePrependedItems.value = [];
    pendingNewItems.value = [];
    pendingNewCount.value = 0;
    liveTotalBoost.value = 0;
    liveNewItemIds.value = {};
    recomputeSinceId();
}

watch(
    () => [props.logs, baseDisplayItems.value],
    () => recomputeSinceId(),
    { deep: true, immediate: true },
);

watch(
    () => ({
        ids: (props.logs?.data ?? []).map((o) => o.id).join(','),
        filters: props.filters,
    }),
    (next, prev) => {
        if (!prev) return;
        if (next.ids !== prev.ids || JSON.stringify(next.filters) !== JSON.stringify(prev.filters)) {
            clearLiveUpdates();
        }
    },
);

useSyncHttpLogsListLiveUpdates({
    enabled: liveUpdatesEnabled,
    sinceId,
    isLoading,
    isLoadingMore,
    hasLoadedBeyondFirstPage,
    getScrollEl: () => getSyncHttpLogsListScrollEl(loadMoreSentinel.value),
    buildUpdatesUrl: buildSyncHttpLogsListUpdatesUrl,
    onPrepend: handleLivePrepend,
    onPending: handleLivePending,
});

function openDetail(id: number) {
    detailId.value = id;
    showDetail.value = true;
}

function closeDetail() {
    showDetail.value = false;
    detailId.value = null;
}

function beforeApplyFilters() {
    clearLiveUpdates();
    resetAccumulation();
}

function clearAllFilters() {
    clearLiveUpdates();
    resetAccumulation();
    router.get(route('sync-logs.index'), {}, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Logs de sync" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <PageHeader
                    compact
                    title="Logs de sync"
                    description="Auditoría HTTP homologada de sincronizaciones y webhooks."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="sync_http_logs"
                            :get-payload="() => ({
                                selectionMode: 'filter',
                                filters: exportFiltersFromUrl(),
                                filteredTotalHint: null,
                            })"
                        />
                    </template>
                </PageHeader>

                <div
                    v-if="loggingPaused"
                    class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                >
                    Registro pausado — no se están guardando nuevas consultas HTTP.
                    <Link
                        v-if="isPlatformAdmin"
                        :href="route('admin.diagnostic-logging.show')"
                        class="ml-1 font-medium underline underline-offset-2"
                    >
                        Actívalo desde Admin (10 min)
                    </Link>
                    <span v-else class="ml-1 text-amber-900/80">
                        Un platform admin puede activarlo temporalmente.
                    </span>
                </div>

                <SyncHttpLogsSearchAndFilters
                    v-model:search-query="searchQuery"
                    :filters="filters"
                    :connections="connections"
                    :webhook-topics="webhook_topics"
                    :before-apply="beforeApplyFilters"
                />

                <div
                    v-if="pendingNewCount > 0"
                    class="sticky top-0 z-20 mb-2 flex justify-center px-2"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full border border-emerald-500/25 bg-emerald-500/[0.08] px-4 py-2 font-mono text-[12px] font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-500/[0.12]"
                        @click="mergePendingLiveUpdates"
                    >
                        <span class="text-[10px]" aria-hidden="true">↑</span>
                        <span>
                            {{ pendingNewCount }}
                            {{ pendingNewCount === 1 ? 'log nuevo' : 'logs nuevos' }}
                            — Ver arriba
                        </span>
                    </button>
                </div>

                <div
                    class="mt-3 overflow-hidden rounded-3xl border border-neutral-200/80 bg-white shadow-[0_4px_24px_rgba(0,0,0,0.06)]"
                >
                    <LogsList
                        :logs="visibleLogs"
                        :live-new-ids="liveNewItemIds"
                        :has-active-filters="hasActiveUrlFilters || !!searchQuery"
                        empty-title="No hay logs"
                        empty-description="Al sincronizar o recibir webhooks aparecerán aquí las llamadas HTTP."
                        @open="openDetail"
                        @clear-filters="clearAllFilters"
                    />
                </div>

                <div
                    ref="loadMoreSentinel"
                    class="h-4 w-full"
                    aria-hidden="true"
                />

                <div
                    v-if="isLoadingMore"
                    class="flex w-full items-center justify-center gap-2 py-3 font-mono text-xs text-slate-500"
                >
                    <span
                        class="inline-block size-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-emerald-600"
                        aria-hidden="true"
                    />
                    Cargando más…
                </div>

                <InfiniteListSummary
                    compact
                    class="font-mono"
                    :total="totalShown"
                    :showing="showingCount"
                    :has-more="hasMorePages"
                    :from="paginationFrom"
                    :to="paginationTo"
                    singular-label="log"
                    plural-label="logs"
                />
            </div>
        </div>

        <SyncHttpLogSlideOver
            :show="showDetail"
            :log-id="detailId ?? undefined"
            @close="closeDetail"
        />
    </AuthenticatedLayout>
</template>
