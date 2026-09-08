<script setup>
import { watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import SpreadsheetSlideOver from '@/Components/Export/SpreadsheetSlideOver.vue'
import { useExportPreview } from '@/composables/useExportPreview'

const open = defineModel('open', { type: Boolean, default: false })
const props = defineProps({
  token: { type: String, default: null },
})

const { loading, error, headers, rows, totalRows, fetchPreview } = useExportPreview()

watch(
  () => [open.value, props.token],
  async ([isOpen, token]) => {
    if (isOpen && token) {
      await fetchPreview(token, { mode: 'page', offset: 0, limit: 200 })
    }
  },
)
</script>

<template>
  <SlideOverShell
    :show="open"
    title="Vista previa del export"
    accessibility-title="Vista previa del export"
    @close="open = false"
  >
    <SpreadsheetSlideOver
      :loading="loading"
      :error="error"
      :headers="headers"
      :rows="rows"
      :total-rows="totalRows"
    />
  </SlideOverShell>
</template>
