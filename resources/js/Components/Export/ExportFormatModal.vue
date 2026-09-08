<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import Dialog from '@/Components/ui/Dialog.vue'
import Button from '@/Components/ui/Button.vue'
import { startExport } from '@/composables/useExportStart'

const open = defineModel('open', { type: Boolean, default: false })

const props = defineProps({
  targetModule: { type: String, required: true },
  selectionMode: { type: String, default: 'filter' },
  ids: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
  query: { type: Object, default: null },
  selectedCount: { type: Number, default: 0 },
  filteredTotalHint: { type: Number, default: null },
})

const format = ref('system') // system | config | preset
const exportConfigId = ref(null)
const exportPresetId = ref(null)
const configs = ref([])
const presets = ref([])
const loading = ref(false)
const submitting = ref(false)

const summary = computed(() => {
  if (props.selectionMode === 'ids') {
    const n = props.selectedCount || props.ids.length
    return `Solo los ${n} seleccionados`
  }
  if (props.filteredTotalHint != null) {
    return `Todos los del filtro (~${props.filteredTotalHint})`
  }
  return 'Todos los del filtro'
})

async function loadOptions() {
  loading.value = true
  try {
    const { data } = await axios.get(route('exports.options'), {
      params: { target_module: props.targetModule },
    })
    configs.value = data.configs || []
    presets.value = data.presets || []
    const defaultConfig = configs.value.find((c) => c.is_default)
    const defaultPreset = presets.value.find((p) => p.is_default)
    if (defaultConfig) {
      format.value = 'config'
      exportConfigId.value = defaultConfig.id
    } else if (defaultPreset) {
      format.value = 'preset'
      exportPresetId.value = defaultPreset.id
    } else {
      format.value = 'system'
    }
  } finally {
    loading.value = false
  }
}

watch(
  () => open.value,
  (v) => {
    if (v) loadOptions()
  },
)

async function confirm() {
  submitting.value = true
  try {
    await startExport({
      targetModule: props.targetModule,
      selectionMode: props.selectionMode,
      ids: props.ids,
      filters: props.filters,
      query: props.query || undefined,
      exportConfigId: format.value === 'config' ? exportConfigId.value : null,
      exportPresetId: format.value === 'preset' ? exportPresetId.value : null,
      label: `Export ${props.targetModule}`,
      totalRowsHint: props.selectionMode === 'ids' ? props.ids.length : props.filteredTotalHint,
    })
    open.value = false
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:open="open"
    title="Exportar a Excel"
  >
    <div class="space-y-4 p-1">
      <p class="text-sm text-slate-600">
        {{ summary }}
      </p>

      <div
        v-if="loading"
        class="text-sm text-slate-500"
      >
        Cargando plantillas…
      </div>
      <div
        v-else
        class="space-y-3"
      >
        <label class="flex items-center gap-2 text-sm">
          <input
            v-model="format"
            type="radio"
            value="system"
          >
          Sistema (columnas por defecto)
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input
            v-model="format"
            type="radio"
            value="config"
            class="mt-1"
            :disabled="!configs.length"
          >
          <span class="flex-1">
            Plantilla de usuario
            <select
              v-model="exportConfigId"
              class="mt-1 w-full rounded border-slate-300 text-sm"
              :disabled="format !== 'config'"
            >
              <option
                v-for="c in configs"
                :key="c.id"
                :value="c.id"
              >
                {{ c.name }}{{ c.is_default ? ' (default)' : '' }}
              </option>
            </select>
          </span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input
            v-model="format"
            type="radio"
            value="preset"
            class="mt-1"
            :disabled="!presets.length"
          >
          <span class="flex-1">
            Preset admin
            <select
              v-model="exportPresetId"
              class="mt-1 w-full rounded border-slate-300 text-sm"
              :disabled="format !== 'preset'"
            >
              <option
                v-for="p in presets"
                :key="p.id"
                :value="p.id"
              >
                {{ p.name }}{{ p.is_default ? ' (default)' : '' }}
              </option>
            </select>
          </span>
        </label>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <Button
          variant="outline"
          @click="open = false"
        >
          Cancelar
        </Button>
        <Button
          :disabled="submitting || loading"
          @click="confirm"
        >
          Exportar
        </Button>
      </div>
    </div>
  </Dialog>
</template>
