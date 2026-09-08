<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        count: number;
        /** When true, banner is informational on the filtered list (no navigation). */
        filtered?: boolean;
        class?: string;
    }>(),
    {
        filtered: false,
    },
);
</script>

<template>
    <div
        v-if="count > 0"
        :class="
            cn(
                'mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900',
                $props.class,
            )
        "
    >
        <template v-if="filtered">
            <p>
                Mostrando
                <span class="font-semibold">{{ count }}</span>
                {{ count === 1 ? 'publicación activa' : 'publicaciones activas' }}
                sin costo definido. Registra una recepción de inventario con costo
                unitario para completar los datos de utilidad.
            </p>
            <Link
                :href="route('inventory.receipts.create')"
                class="mt-1 inline-block font-medium text-amber-950 underline underline-offset-2 hover:no-underline"
            >
                Registrar recepción con costo
            </Link>
        </template>
        <Link
            v-else
            :href="route('publications.index', { without_cost: 1 })"
            class="block font-medium text-amber-950 underline-offset-2 hover:underline"
        >
            Hay {{ count }}
            {{ count === 1 ? 'publicación' : 'publicaciones' }}
            del marketplace sin costo definido. Completa las recepciones para calcular utilidad.
        </Link>
    </div>
</template>
