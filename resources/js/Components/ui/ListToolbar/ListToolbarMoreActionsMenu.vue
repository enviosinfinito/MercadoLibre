<template>
  <div class="relative">
    <button
      type="button"
      class="flex h-10 min-h-10 w-9 items-center justify-center text-neutral-600 transition-colors hover:bg-neutral-50 focus:outline-none"
      title="Más acciones"
      aria-label="Más acciones"
      @click="open = !open"
    >
      <MoreVertical class="h-4 w-4 shrink-0" />
    </button>
    <div
      v-if="open"
      class="absolute right-0 z-40 mt-1 w-64 overflow-hidden rounded-xl border border-neutral-200 bg-white py-1 shadow-lg"
    >
      <template v-for="group in groups" :key="group.title">
        <div class="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-neutral-400">
          {{ group.title }}
        </div>
        <button
          v-for="item in group.items"
          :key="item.id"
          type="button"
          class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-neutral-50 disabled:opacity-50"
          :disabled="item.disabled"
          @click="onSelect(item)"
        >
          <span class="font-medium text-neutral-800">{{ item.label }}</span>
        </button>
      </template>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { MoreVertical } from 'lucide-vue-next'

defineProps({
  groups: { type: Array, default: () => [] },
  modelValue: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'select-item'])

const open = ref(false)

function onSelect(item) {
  emit('select-item', item)
  open.value = false
  emit('update:modelValue', false)
}

function onDocClick(e) {
  if (!open.value) return
  if (!e.target?.closest?.('.relative')) open.value = false
}

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>
