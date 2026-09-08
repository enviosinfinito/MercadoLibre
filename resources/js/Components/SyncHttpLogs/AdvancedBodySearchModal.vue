<script setup>
import { computed, ref, watch } from 'vue'
import Dialog from '@/Components/ui/Dialog.vue'
import Button from '@/Components/ui/Button.vue'
import FilterDateRangeFieldLinked from '@/Components/ui/FilterDateRangeFieldLinked.vue'
import { connectionFilterLabel } from '@/lib/connectionLabel'

const props = defineProps({
  open: { type: Boolean, default: false },
  connections: { type: Array, default: () => [] },
  initial: {
    type: Object,
    default: () => ({
      body_search: '',
      connection_id: '',
      from: '',
      to: '',
    }),
  },
})

const emit = defineEmits(['update:open', 'apply', 'close'])

const draft = ref({
  body_search: '',
  connection_id: '',
  from: '',
  to: '',
})

watch(
  () => [props.open, props.initial],
  ([open]) => {
    if (!open) return
    draft.value = {
      body_search: props.initial.body_search ?? '',
      connection_id: props.initial.connection_id != null && props.initial.connection_id !== ''
        ? String(props.initial.connection_id)
        : '',
      from: props.initial.from ?? '',
      to: props.initial.to ?? '',
    }
  },
  { immediate: true, deep: true },
)

const error = computed(() => {
  if (!String(draft.value.body_search || '').trim()) return 'Escribe un término a buscar en el body.'
  if (!draft.value.connection_id) return 'Selecciona una conexión (scope obligatorio).'
  if (!draft.value.from || !draft.value.to) return 'Indica un rango de fechas (desde y hasta).'
  return null
})

function close() {
  emit('update:open', false)
  emit('close')
}

function apply() {
  if (error.value) return
  emit('apply', {
    body_search: String(draft.value.body_search).trim(),
    connection_id: draft.value.connection_id,
    from: draft.value.from,
    to: draft.value.to,
  })
  close()
}
</script>

<template>
  <Dialog
    :open="open"
    title="Búsqueda avanzada en body"
    class="max-w-md font-mono"
    @update:open="emit('update:open', $event)"
    @close="close"
  >
    <p class="mb-4 text-xs text-neutral-500">
      Busca en request/response redactados (LIKE). Requiere conexión y rango de fechas.
    </p>
    <div class="space-y-4 text-sm">
      <div>
        <label class="mb-1.5 block text-xs font-medium text-neutral-500">Término</label>
        <input
          v-model="draft.body_search"
          type="text"
          class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 font-mono text-sm"
          placeholder='ej. "orders_v2" o MLM…'
        >
      </div>
      <div>
        <label class="mb-1.5 block text-xs font-medium text-neutral-500">Conexión</label>
        <select
          v-model="draft.connection_id"
          class="h-10 w-full rounded-lg border border-neutral-200 bg-white px-3 text-sm"
        >
          <option value="">Seleccionar…</option>
          <option
            v-for="c in connections"
            :key="c.id"
            :value="String(c.id)"
          >
            {{ connectionFilterLabel(c) }}
          </option>
        </select>
      </div>
      <FilterDateRangeFieldLinked
        :filter-object="draft"
        start-key="from"
        end-key="to"
        label="Período"
      />
      <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
    </div>
    <template #footer>
      <Button variant="outline" size="sm" @click="close">Cancelar</Button>
      <Button size="sm" :disabled="!!error" @click="apply">Buscar</Button>
    </template>
  </Dialog>
</template>
