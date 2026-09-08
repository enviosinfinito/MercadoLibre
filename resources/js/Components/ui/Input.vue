<script setup lang="ts">
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        type?: string;
        placeholder?: string;
        disabled?: boolean;
        required?: boolean;
        id?: string;
        name?: string;
        class?: string;
        step?: string | number;
        min?: string | number;
        max?: string | number;
    }>(),
    {
        type: 'text',
        modelValue: '',
        disabled: false,
        required: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <input
        :id="id"
        :name="name"
        :type="type"
        :value="modelValue ?? ''"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        :step="step"
        :min="min"
        :max="max"
        :class="
            cn(
                'flex h-10 w-full rounded-md border border-input bg-white px-3 py-2 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                $props.class,
            )
        "
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
</template>
