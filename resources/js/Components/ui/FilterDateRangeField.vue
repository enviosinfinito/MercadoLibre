<template>
  <div class="filter-date-range-field">
    <label v-if="label" class="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-neutral-500">
      <Calendar v-if="showIcon" class="h-3.5 w-3.5 text-neutral-400" aria-hidden="true" />
      {{ label }}
    </label>
    <BaseDateRangePicker
      :model-value="modelValue"
      :show-hint="showHint"
      :placeholder="placeholder"
      @update:model-value="$emit('update:modelValue', $event)"
    />
    <div v-if="showPresets" class="mt-2 flex flex-wrap gap-1.5">
      <button
        v-for="preset in resolvedPresets"
        :key="preset.key"
        type="button"
        class="rounded-full border border-neutral-200 bg-white px-2.5 py-1 text-[11px] font-medium text-neutral-600 hover:border-neutral-300 hover:bg-neutral-50"
        @click="onPresetClick(preset)"
      >
        {{ preset.label }}
      </button>
      <button
        v-if="hasActiveRange && showClearButton"
        type="button"
        class="rounded-full border border-transparent px-2.5 py-1 text-[11px] font-medium text-neutral-500 hover:text-neutral-800"
        @click="onClear"
      >
        Limpiar
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Calendar } from 'lucide-vue-next'
import BaseDateRangePicker from '@/Components/ui/BaseDateRangePicker.vue'
import { getDefaultDateRangePresets } from '@/utils/filterDates'

const props = defineProps({
  modelValue: { type: Array, default: null },
  label: { type: String, default: 'Período' },
  placeholder: { type: String, default: 'Inicio - Fin' },
  showIcon: { type: Boolean, default: true },
  showHint: { type: Boolean, default: true },
  showPresets: { type: Boolean, default: true },
  showClearButton: { type: Boolean, default: true },
  presets: { type: Array, default: null },
})

const emit = defineEmits(['update:modelValue', 'clear', 'preset'])

const resolvedPresets = computed(() => props.presets ?? getDefaultDateRangePresets())

const hasActiveRange = computed(() => {
  const mv = props.modelValue
  return Array.isArray(mv) && mv[0] != null && mv[1] != null
})

function onPresetClick(preset) {
  if (preset?.range) {
    emit('update:modelValue', [
      new Date(preset.range[0]),
      new Date(preset.range[1]),
    ])
    emit('preset', preset)
  }
}

function onClear() {
  emit('update:modelValue', null)
  emit('clear')
}
</script>
