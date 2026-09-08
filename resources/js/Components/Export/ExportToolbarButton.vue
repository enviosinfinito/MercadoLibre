<script setup>
import { ref } from 'vue'
import Button from '@/Components/ui/Button.vue'
import ExportFormatModal from '@/Components/Export/ExportFormatModal.vue'
import { FileDown } from 'lucide-vue-next'

const props = defineProps({
  targetModule: { type: String, required: true },
  /** () => ({ selectionMode, ids, filters, query, selectedCount, filteredTotalHint }) */
  getPayload: { type: Function, required: true },
  label: { type: String, default: 'Exportar' },
})

const open = ref(false)
const payload = ref({
  selectionMode: 'filter',
  ids: [],
  filters: {},
  query: null,
  selectedCount: 0,
  filteredTotalHint: null,
})

function openModal() {
  payload.value = {
    selectionMode: 'filter',
    ids: [],
    filters: {},
    query: null,
    selectedCount: 0,
    filteredTotalHint: null,
    ...props.getPayload(),
  }
  open.value = true
}
</script>

<template>
  <Button
    variant="outline"
    size="sm"
    @click="openModal"
  >
    <FileDown class="mr-1.5 h-4 w-4" />
    {{ label }}
  </Button>

  <ExportFormatModal
    v-model:open="open"
    :target-module="targetModule"
    :selection-mode="payload.selectionMode"
    :ids="payload.ids"
    :filters="payload.filters"
    :query="payload.query"
    :selected-count="payload.selectedCount"
    :filtered-total-hint="payload.filteredTotalHint"
  />
</template>
