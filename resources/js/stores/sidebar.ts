import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';

const STORAGE_KEY = 'sidebar.pinned';

function readPinned(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

export const useSidebarStore = defineStore('sidebar', () => {
    const pinned = ref(readPinned());
    const hovered = ref(false);

    const expanded = computed(() => pinned.value || hovered.value);

    function setHovered(value: boolean): void {
        hovered.value = value;
    }

    function togglePinned(): void {
        pinned.value = !pinned.value;
    }

    function setPinned(value: boolean): void {
        pinned.value = value;
    }

    watch(pinned, (value) => {
        try {
            localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
        } catch {
            // ignore quota / private mode
        }
    });

    return {
        pinned,
        hovered,
        expanded,
        setHovered,
        togglePinned,
        setPinned,
    };
});
