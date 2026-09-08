import { defineStore } from 'pinia';
import { ref, watch } from 'vue';

const STORAGE_KEY = 'orders.sound.enabled';

function readEnabled(): boolean {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw === null) {
            return true;
        }

        return raw === '1';
    } catch {
        return true;
    }
}

export const useOrderSoundStore = defineStore('orderSound', () => {
    const enabled = ref(readEnabled());

    function toggle(): void {
        enabled.value = !enabled.value;
    }

    function setEnabled(value: boolean): void {
        enabled.value = value;
    }

    watch(enabled, (value) => {
        try {
            localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
        } catch {
            // ignore quota / private mode
        }
    });

    return {
        enabled,
        toggle,
        setEnabled,
    };
});
