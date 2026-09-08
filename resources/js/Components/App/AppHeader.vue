<script setup lang="ts">
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { unlockOrderSaleSound } from '@/composables/useOrderSaleSound';
import { useOrderSoundStore } from '@/stores/orderSound';
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, Volume2, VolumeX, X } from 'lucide-vue-next';
import { computed } from 'vue';
import { storeToRefs } from 'pinia';

const props = defineProps<{
    sidebarOpen: boolean;
}>();

const emit = defineEmits<{
    'toggle-sidebar': [];
}>();

const page = usePage();
const orderSound = useOrderSoundStore();
const { enabled: soundEnabled } = storeToRefs(orderSound);

const workspace = computed(() => {
    return (page.props as {
        workspace?: { id: number; name: string; slug: string } | null;
    }).workspace;
});

const user = computed(() => page.props.auth.user);

function toggleSaleSound(): void {
    unlockOrderSaleSound();
    orderSound.toggle();
}
</script>

<template>
    <header
        class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-white/80"
    >
        <button
            type="button"
            class="inline-flex items-center justify-center rounded-md p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 md:hidden"
            aria-label="Toggle navigation"
            @click="emit('toggle-sidebar')"
        >
            <X
                v-if="sidebarOpen"
                class="h-5 w-5"
            />
            <Menu
                v-else
                class="h-5 w-5"
            />
        </button>

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-slate-900">
                {{ workspace?.name ?? 'Sin workspace' }}
            </p>
            <p
                v-if="workspace?.slug"
                class="truncate text-xs text-slate-500"
            >
                {{ workspace.slug }}
            </p>
        </div>

        <Link
            :href="route('workspaces.index')"
            class="hidden text-xs font-medium text-slate-500 transition hover:text-brand sm:inline"
        >
            Cambiar workspace
        </Link>

        <button
            type="button"
            class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white p-2 text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
            :aria-label="soundEnabled ? 'Silenciar sonido de ventas' : 'Activar sonido de ventas'"
            :title="soundEnabled ? 'Sonido de ventas: activado' : 'Sonido de ventas: silenciado'"
            @click="toggleSaleSound"
        >
            <Volume2
                v-if="soundEnabled"
                class="h-4 w-4"
            />
            <VolumeX
                v-else
                class="h-4 w-4"
            />
        </button>

        <div class="relative">
            <Dropdown align="right" width="48">
                <template #trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                    >
                        <span class="max-w-[10rem] truncate">{{ user.name }}</span>
                        <svg
                            class="h-4 w-4 text-slate-400"
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                </template>

                <template #content>
                    <div class="border-b border-slate-100 px-4 py-2">
                        <p class="truncate text-sm font-medium text-slate-900">
                            {{ user.name }}
                        </p>
                        <p class="truncate text-xs text-slate-500">
                            {{ user.email }}
                        </p>
                    </div>
                    <DropdownLink :href="route('profile.edit')">
                        Profile
                    </DropdownLink>
                    <DropdownLink
                        :href="route('workspaces.members.index')"
                    >
                        Members
                    </DropdownLink>
                    <DropdownLink
                        :href="route('logout')"
                        method="post"
                        as="button"
                    >
                        Log Out
                    </DropdownLink>
                </template>
            </Dropdown>
        </div>
    </header>
</template>
