<script setup lang="ts">
import { defineAsyncComponent, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ConnectionDetailPanel from '@/Components/Connections/ConnectionDetailPanel.vue';
import type {
    SyncCatalogResource,
    SyncProfileProp,
} from '@/Components/Domain/SyncConfigurator.vue';
import type { PlatformDef } from '@/Components/Domain/PlatformCard.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, usePage } from '@inertiajs/vue3';

const ConnectionAccountProfileSlideOver = defineAsyncComponent(
    () => import('@/Components/Connections/ConnectionAccountProfileSlideOver.vue'),
);
const ConnectionAccountStatusSlideOver = defineAsyncComponent(
    () => import('@/Components/Connections/ConnectionAccountStatusSlideOver.vue'),
);
const ConnectionReputationDetailSlideOver = defineAsyncComponent(
    () => import('@/Components/Connections/ConnectionReputationDetailSlideOver.vue'),
);
const ConnectionPurchaseExperienceSlideOver = defineAsyncComponent(
    () => import('@/Components/Connections/ConnectionPurchaseExperienceSlideOver.vue'),
);

const props = defineProps<{
    connection: {
        id: number;
        provider: string;
        external_user_id: string | null;
        site_id: string | null;
        display_name: string | null;
        permalink: string | null;
        avatar_url: string | null;
        reputation_level: string | null;
        power_seller_status: string | null;
        reputation_meta?: Record<string, unknown> | null;
        reputation_synced_at?: string | null;
        account_profile?: Record<string, unknown> | null;
        account_profile_synced_at?: string | null;
        color?: string | null;
        status: string;
        needs_reauthorization: boolean;
        freshness_status: string | null;
        last_synced_at: string | null;
    };
    reputation_timeline?: {
        points: Array<Record<string, unknown>>;
        milestones: Array<Record<string, unknown>>;
    };
    purchase_experience_summary?: {
        enabled: boolean;
        totals_by_color: Record<string, number>;
        without_data: number;
        top_problems: Array<{ key: string; title: string; count: number }>;
    };
    purchase_experience_listings?: Array<Record<string, unknown>>;
    catalog: SyncCatalogResource[];
    profiles: SyncProfileProp[];
    platforms: PlatformDef[];
}>();

const page = usePage();
const banner = ref<string | null>(null);
const openSection = ref<string | null>(null);

const platformName =
    props.platforms.find((p) => p.id === props.connection.provider)?.name ??
    props.connection.provider;

const applyFlash = () => {
    const flash = page.props.flash as { success?: string } | undefined;
    if (flash?.success) {
        banner.value = flash.success;
    }
};

onMounted(() => applyFlash());

watch(
    () => (page.props.flash as { success?: string } | undefined)?.success,
    () => applyFlash(),
);

const onOpenAccountSection = (section: string) => {
    openSection.value = section;
};
</script>

<template>
    <Head :title="`Sync · ${platformName}`" />

    <AuthenticatedLayout>
        <div class="py-5">
            <div class="w-full space-y-3 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="banner"
                    class="rounded-xl border border-emerald-200/80 bg-emerald-50 px-3 py-2 text-xs text-emerald-900"
                >
                    {{ banner }}
                </div>

                <PageHeader compact :title="`Sync · ${platformName}`">
                    <template #actions>
                        <Button
                            as="a"
                            :href="route('connections.index')"
                            variant="outline"
                            size="sm"
                            class="h-7 px-2.5 text-[11px]"
                        >
                            Volver
                        </Button>
                    </template>
                </PageHeader>

                <div
                    class="overflow-hidden rounded-xl border border-slate-200/70 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
                >
                    <ConnectionDetailPanel
                        :connection="connection"
                        :catalog="catalog"
                        :profiles="profiles"
                        :platforms="platforms"
                        :reputation-timeline="reputation_timeline"
                        :purchase-experience-summary="purchase_experience_summary"
                        compact
                        @open-account-section="onOpenAccountSection"
                    />
                </div>
            </div>
        </div>

        <ConnectionAccountProfileSlideOver
            :show="openSection === 'profile'"
            :connection="connection"
            @close="openSection = null"
        />
        <ConnectionAccountStatusSlideOver
            :show="openSection === 'status'"
            :connection="connection"
            @close="openSection = null"
        />
        <ConnectionReputationDetailSlideOver
            :show="openSection === 'reputation'"
            :connection="connection"
            :reputation-timeline="reputation_timeline"
            @close="openSection = null"
        />
        <ConnectionPurchaseExperienceSlideOver
            :show="openSection === 'purchase_experience'"
            :connection="connection"
            :purchase-experience-summary="purchase_experience_summary"
            :purchase-experience-listings="purchase_experience_listings ?? []"
            @close="openSection = null"
        />
    </AuthenticatedLayout>
</template>
