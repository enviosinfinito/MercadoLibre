<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Button from '@/Components/ui/Button.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    token: string;
    provider: string;
    provider_label: string;
    workspace_name: string | null;
    expires_at: string | null;
    start_url: string;
}>();
</script>

<template>
    <GuestLayout>
        <Head :title="`Conectar ${provider_label}`" />

        <div class="space-y-4 text-center">
            <h1 class="text-xl font-semibold text-slate-900">
                Conectar {{ provider_label }}
            </h1>
            <p class="text-sm text-slate-600">
                Vas a autorizar
                <span class="font-medium text-slate-900">{{ provider_label }}</span>
                para el workspace
                <span class="font-medium text-slate-900">{{ workspace_name ?? 'solicitado' }}</span>.
                No necesitas iniciar sesión en esta plataforma.
            </p>
            <p v-if="expires_at" class="text-xs text-slate-500">
                Este enlace es de un solo uso.
            </p>
            <div class="pt-2">
                <Button class="w-full" as="a" :href="start_url">
                    Continuar con {{ provider_label }}
                </Button>
            </div>
        </div>
    </GuestLayout>
</template>
