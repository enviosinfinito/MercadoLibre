<template>
  <div class="filter-multi-select-field">
    <label v-if="label" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-neutral-500">
      {{ label }}
      <span v-if="selectedCount" class="ml-1 inline-flex min-w-[1.125rem] items-center justify-center rounded-full bg-[rgb(var(--brand-primary-rgb)/0.1)] px-1.5 text-[10px] font-semibold text-[var(--brand-primary)]">
        {{ selectedCount }}
      </span>
    </label>
    <div ref="rootRef" class="relative">
      <button
        type="button"
        class="flex w-full items-center justify-between gap-2 rounded-xl border border-neutral-200 bg-neutral-50/50 px-3 py-2.5 text-left text-sm transition hover:border-[rgb(var(--brand-primary-rgb)/0.25)]"
        :class="selectedCount > 0 || isOpen ? 'border-[rgb(var(--brand-primary-rgb)/0.35)]' : ''"
        :aria-expanded="isOpen"
        @click.stop="toggleDropdown"
      >
        <span class="min-w-0 flex-1 truncate" :class="selectedCount > 0 ? 'text-neutral-900' : 'text-neutral-400'">
          {{ triggerLabel }}
        </span>
        <ChevronDown class="h-3.5 w-3.5 shrink-0 text-neutral-400 transition" :class="isOpen ? 'rotate-180' : ''" />
      </button>

      <div v-if="isOpen" class="absolute z-30 mt-1.5 w-full overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg">
        <div v-if="searchable" class="relative border-b border-neutral-100">
          <Search class="pointer-events-none absolute left-2.5 top-2.5 h-3.5 w-3.5 text-neutral-400" />
          <Input
            v-model="searchTerm"
            placeholder="Buscar..."
            class="rounded-none border-0 pl-8 shadow-none focus-visible:ring-0"
          />
        </div>
        <div class="max-h-48 overflow-y-auto">
          <p v-if="filteredOptions.length === 0" class="p-3 text-center text-xs text-neutral-400">
            Sin resultados
          </p>
          <button
            v-for="option in filteredOptions"
            :key="option.value"
            type="button"
            class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-[rgb(var(--brand-primary-rgb)/0.04)]"
            @click="toggleOption(option.value)"
          >
            <span
              class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border"
              :class="isSelected(option.value)
                ? 'border-[var(--brand-primary)] bg-[var(--brand-primary)] text-white'
                : 'border-neutral-300'"
            >
              <Check v-if="isSelected(option.value)" class="h-2.5 w-2.5" />
            </span>
            <span class="text-[13px] text-neutral-700">{{ option.label }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Check, ChevronDown, Search } from 'lucide-vue-next'
import Input from '@/Components/ui/Input.vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Sin filtrar' },
  searchable: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue'])

const isOpen = ref(false)
const searchTerm = ref('')
const rootRef = ref(null)

const normalizedOptions = computed(() => (
  props.options.map((option) => ({
    value: String(option.value ?? option.id ?? ''),
    label: String(option.label ?? option.name ?? option.value ?? option.id ?? ''),
  }))
))

const selectedValues = computed(() => (
  Array.isArray(props.modelValue) ? props.modelValue.map(String) : []
))

const selectedCount = computed(() => selectedValues.value.length)

const filteredOptions = computed(() => {
  const term = searchTerm.value.trim().toLowerCase()
  if (!term) return normalizedOptions.value
  return normalizedOptions.value.filter((option) => option.label.toLowerCase().includes(term))
})

const triggerLabel = computed(() => {
  const count = selectedCount.value
  if (count === 0) return props.placeholder
  const labels = selectedValues.value
    .map((value) => normalizedOptions.value.find((option) => option.value === value)?.label)
    .filter(Boolean)
  if (count === 1) return labels[0] || props.placeholder
  if (labels.length >= 2) {
    return `${labels.slice(0, 2).join(', ')}${count > 2 ? ` +${count - 2}` : ''}`
  }
  return `${count} seleccionados`
})

function isSelected(value) {
  return selectedValues.value.includes(String(value))
}

function toggleOption(value) {
  const normalized = String(value)
  const current = [...selectedValues.value]
  const index = current.indexOf(normalized)
  if (index > -1) current.splice(index, 1)
  else current.push(normalized)
  emit('update:modelValue', current)
}

function toggleDropdown() {
  isOpen.value = !isOpen.value
  if (!isOpen.value) searchTerm.value = ''
}

function closeDropdown() {
  isOpen.value = false
  searchTerm.value = ''
}

function onDocumentClick(event) {
  if (!isOpen.value || !rootRef.value) return
  if (!rootRef.value.contains(event.target)) closeDropdown()
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>
