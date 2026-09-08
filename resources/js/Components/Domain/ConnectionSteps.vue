<script setup lang="ts">
import { cn } from '@/lib/utils';

const props = defineProps<{
    /** 1 = elegir, 2 = conectar, 3 = listo */
    currentStep: 1 | 2 | 3;
}>();

const steps = [
    { n: 1, label: 'Elegir plataforma' },
    { n: 2, label: 'Conectar cuenta' },
    { n: 3, label: 'Listo' },
] as const;

const isActive = (n: number) => n === props.currentStep;
const isDone = (n: number) => n < props.currentStep;
</script>

<template>
    <ol class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-center sm:gap-0">
        <li
            v-for="(step, index) in steps"
            :key="step.n"
            class="flex items-center sm:flex-1"
        >
            <div class="flex items-center gap-3">
                <span
                    :class="
                        cn(
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold transition-colors',
                            isActive(step.n) && 'bg-brand text-white',
                            isDone(step.n) && 'bg-brand-muted text-brand',
                            !isActive(step.n) &&
                                !isDone(step.n) &&
                                'bg-secondary text-muted-foreground',
                        )
                    "
                >
                    {{ step.n }}
                </span>
                <span
                    :class="
                        cn(
                            'text-sm font-medium',
                            isActive(step.n) ? 'text-foreground' : 'text-muted-foreground',
                        )
                    "
                >
                    {{ step.label }}
                </span>
            </div>
            <div
                v-if="index < steps.length - 1"
                class="mx-4 hidden h-px flex-1 bg-border sm:block"
                aria-hidden="true"
            />
        </li>
    </ol>
</template>
