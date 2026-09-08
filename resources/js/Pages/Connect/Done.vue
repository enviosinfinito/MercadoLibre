<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    status: string;
    tone: 'success' | 'error';
    message: string;
}>();
</script>

<template>
    <GuestLayout>
        <Head title="Conexión" />

        <div class="space-y-4 text-center">
            <div
                class="mx-auto flex h-12 w-12 items-center justify-center rounded-full text-lg font-semibold"
                :class="
                    tone === 'success'
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-red-100 text-red-700'
                "
            >
                {{ tone === 'success' ? '✓' : '!' }}
            </div>
            <h1 class="text-xl font-semibold text-slate-900">
                {{ tone === 'success' ? 'Conexión completada' : 'No se pudo conectar' }}
            </h1>
            <p class="text-sm text-slate-600">
                {{ message }}
            </p>
            <p class="pt-2 text-xs text-slate-500">
                <Link href="/" class="text-brand underline-offset-2 hover:underline">
                    Ir al inicio
                </Link>
            </p>
        </div>
    </GuestLayout>
</template>
