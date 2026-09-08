<script setup lang="ts">
import AppHeader from '@/Components/App/AppHeader.vue';
import AppSidebar from '@/Components/App/AppSidebar.vue';
import ExportProgressModals from '@/Components/Export/ExportProgressModals.vue';
import { useOrderSaleSound } from '@/composables/useOrderSaleSound';
import { usePage } from '@inertiajs/vue3';
import { useSidebarStore } from '@/stores/sidebar';
import { useWorkspaceStore } from '@/stores/workspace';
import { computed, ref, watch } from 'vue';

const mobileOpen = ref(false);
const page = usePage();
const workspaceStore = useWorkspaceStore();
const sidebar = useSidebarStore();

useOrderSaleSound();

const workspaceId = computed(() => {
    const ws = (page.props as { workspace?: { id?: number } | null }).workspace;
    return ws?.id ?? null;
});

/** Desktop content offset: only pinned uses full width; hover overlays. */
const mainOffsetClass = computed(() =>
    sidebar.pinned ? 'md:pl-64' : 'md:pl-16',
);

watch(
    workspaceId,
    (id) => {
        workspaceStore.setWorkspaceId(id);
    },
    { immediate: true },
);

watch(
    () => page.url,
    () => {
        mobileOpen.value = false;
    },
);

function toggleMobileSidebar(): void {
    mobileOpen.value = !mobileOpen.value;
}

function closeMobileSidebar(): void {
    mobileOpen.value = false;
}
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <!-- Mobile overlay -->
        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-40 bg-slate-900/40 md:hidden"
            aria-hidden="true"
            @click="closeMobileSidebar"
        />

        <!-- Mobile drawer (always expanded labels) -->
        <div
            class="fixed inset-y-0 left-0 z-50 transform transition-transform duration-200 md:hidden"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <AppSidebar
                force-expanded
                @close="closeMobileSidebar"
            />
        </div>

        <!-- Desktop sidebar: rail + expand on hover / pin -->
        <div
            class="pointer-events-none fixed inset-y-0 left-0 z-50 hidden md:block"
            :class="sidebar.expanded ? 'w-64' : 'w-16'"
        >
            <div
                class="pointer-events-auto h-full transition-shadow duration-200"
                :class="sidebar.expanded && !sidebar.pinned ? 'shadow-lg' : 'shadow-sm'"
            >
                <AppSidebar />
            </div>
        </div>

        <!-- Main column -->
        <div
            class="flex min-h-screen flex-col transition-[padding] duration-200 ease-out"
            :class="mainOffsetClass"
        >
            <AppHeader
                :sidebar-open="mobileOpen"
                @toggle-sidebar="toggleMobileSidebar"
            />

            <header
                v-if="$slots.header"
                class="border-b border-slate-200 bg-white"
            >
                <div class="px-4 py-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <main
                class="flex-1"
                data-content-safe-area
            >
                <slot />
            </main>
        </div>

        <ExportProgressModals />
    </div>
</template>
