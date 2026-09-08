<script setup lang="ts">
import { computed } from 'vue';
import PlatformCard, {
    type PlatformConnection,
    type PlatformDef,
} from '@/Components/Domain/PlatformCard.vue';

const props = defineProps<{
    platforms: PlatformDef[];
    connections: PlatformConnection[];
}>();

const emit = defineEmits<{
    disconnect: [id: number];
}>();

const popular = computed(() => props.platforms.filter((p) => p.group === 'popular'));
const other = computed(() => props.platforms.filter((p) => p.group !== 'popular'));

const connectionsFor = (providerId: string) =>
    props.connections.filter((c) => c.provider === providerId);
</script>

<template>
    <div class="space-y-8">
        <section v-if="popular.length">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                Más populares
            </h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <PlatformCard
                    v-for="platform in popular"
                    :key="platform.id"
                    :platform="platform"
                    :connections="connectionsFor(platform.id)"
                    @disconnect="emit('disconnect', $event)"
                />
            </div>
        </section>

        <section v-if="other.length">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                Otras plataformas
            </h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <PlatformCard
                    v-for="platform in other"
                    :key="platform.id"
                    :platform="platform"
                    :connections="connectionsFor(platform.id)"
                    @disconnect="emit('disconnect', $event)"
                />
            </div>
        </section>
    </div>
</template>
