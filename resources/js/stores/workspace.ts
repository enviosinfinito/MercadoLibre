import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useWorkspaceStore = defineStore('workspace', () => {
    const currentWorkspaceId = ref<number | null>(null);

    function setWorkspaceId(id: number | null): void {
        currentWorkspaceId.value = id;
    }

    return {
        currentWorkspaceId,
        setWorkspaceId,
    };
});
