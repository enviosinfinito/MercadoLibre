<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';

defineProps<{
    href: string;
    active?: boolean;
    icon?: Component;
    label: string;
    expanded?: boolean;
}>();

const emit = defineEmits<{
    click: [];
}>();
</script>

<template>
    <Link
        :href="href"
        class="group relative flex items-center rounded-md py-2 text-sm font-medium transition"
        :class="[
            expanded ? 'gap-2.5 px-2.5' : 'justify-center px-0',
            active
                ? 'bg-brand-muted text-brand'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
        ]"
        :title="expanded ? undefined : label"
        @click="emit('click')"
    >
        <component
            :is="icon"
            v-if="icon"
            class="h-4 w-4 shrink-0"
            :class="active ? 'text-brand' : 'text-slate-400 group-hover:text-slate-600'"
            aria-hidden="true"
        />
        <span
            v-show="expanded"
            class="truncate"
        >
            {{ label }}
        </span>
    </Link>
</template>
