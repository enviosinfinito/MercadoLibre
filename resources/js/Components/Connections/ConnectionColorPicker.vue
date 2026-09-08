<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    CONNECTION_COLOR_PALETTE,
    normalizeConnectionColor,
    resolveConnectionColor,
} from '@/lib/connectionColor';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import { cn } from '@/lib/utils';

const props = defineProps({
    connection: { type: Object, required: true },
});

const emit = defineEmits(['updated']);

const localColor = ref(resolveConnectionColor(props.connection?.color));
const saving = ref(false);

watch(
    () => props.connection?.color,
    (next) => {
        localColor.value = resolveConnectionColor(next);
    },
);

const previewConnection = computed(() => ({
    ...props.connection,
    color: localColor.value,
}));

const isSelected = (hex) =>
    normalizeConnectionColor(hex) === normalizeConnectionColor(localColor.value);

const persist = (hex) => {
    const color = normalizeConnectionColor(hex);
    if (!color || color === normalizeConnectionColor(props.connection?.color)) {
        localColor.value = resolveConnectionColor(props.connection?.color);
        return;
    }

    const previous = localColor.value;
    localColor.value = color;
    saving.value = true;

    router.patch(
        route('connections.color.update', props.connection.id),
        { color },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                emit('updated', color);
            },
            onError: () => {
                localColor.value = previous;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};

const onCustomInput = (event) => {
    persist(event.target.value);
};
</script>

<template>
    <div class="space-y-2.5">
        <div class="flex items-center justify-between gap-2">
            <div>
                <p class="text-[12px] font-medium text-slate-800">Color de identificación</p>
                <p class="text-[11px] text-muted-foreground">
                    Se usa en órdenes y listados para distinguir cuentas.
                </p>
            </div>
            <ConnectionChip :connection="previewConnection" :compact="false" />
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <button
                v-for="hex in CONNECTION_COLOR_PALETTE"
                :key="hex"
                type="button"
                :disabled="saving"
                :title="hex"
                :aria-label="`Color ${hex}`"
                :aria-pressed="isSelected(hex)"
                :class="
                    cn(
                        'size-7 rounded-full border-2 transition-transform',
                        isSelected(hex)
                            ? 'scale-110 border-slate-900 ring-2 ring-slate-900/10'
                            : 'border-white shadow-sm hover:scale-105',
                    )
                "
                :style="{ backgroundColor: hex }"
                @click="persist(hex)"
            />

            <label
                :class="
                    cn(
                        'relative flex size-7 cursor-pointer items-center justify-center overflow-hidden rounded-full border-2 border-dashed border-slate-300 bg-white text-[10px] font-semibold text-slate-500 transition-colors hover:border-slate-400',
                        saving && 'pointer-events-none opacity-60',
                    )
                "
                title="Color personalizado"
            >
                <span aria-hidden="true">+</span>
                <input
                    type="color"
                    class="absolute inset-0 cursor-pointer opacity-0"
                    :value="localColor"
                    :disabled="saving"
                    @input="onCustomInput"
                />
            </label>
        </div>
    </div>
</template>
