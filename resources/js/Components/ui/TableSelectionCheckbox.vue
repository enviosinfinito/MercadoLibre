<template>
  <input
    ref="inputRef"
    type="checkbox"
    class="size-4 cursor-pointer rounded border-slate-400 text-brand shadow-sm focus:ring-brand"
    :class="inputClass"
    :checked="checked"
    :aria-label="ariaLabel"
    @change="$emit('change', $event)"
  >
</template>

<script setup>
import { nextTick, ref, watch } from 'vue'

const props = defineProps({
  checked: { type: Boolean, default: false },
  indeterminate: { type: Boolean, default: false },
  ariaLabel: { type: String, default: 'Seleccionar' },
  inputClass: { type: String, default: '' },
})

defineEmits(['change'])

const inputRef = ref(null)

function syncIndeterminate() {
  if (inputRef.value) {
    inputRef.value.indeterminate = Boolean(props.indeterminate) && !props.checked
  }
}

watch(
  () => [props.checked, props.indeterminate],
  async () => {
    await nextTick()
    syncIndeterminate()
  },
  { immediate: true },
)
</script>
