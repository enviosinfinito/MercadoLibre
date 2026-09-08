<script setup>
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/App/PageHeader.vue'
import Button from '@/Components/ui/Button.vue'
import Badge from '@/Components/ui/Badge.vue'
import Card from '@/Components/ui/Card.vue'
import ExportConfigEditor from '@/Components/Export/ExportConfigEditor.vue'
import ScheduledReportModal from '@/Components/Export/ScheduledReportModal.vue'
import ExportPreviewSlideOver from '@/Components/Export/ExportPreviewSlideOver.vue'
import { startExport } from '@/composables/useExportStart'

const props = defineProps({
  modules: Array,
  activeModule: String,
  configs: Array,
  presets: Array,
  schedules: Array,
  history: Object,
  enabledDeliveryChannels: Array,
  availableColumns: Array,
})

const mainTab = ref('config')
const editorOpen = ref(false)
const editorMode = ref('config')
const editing = ref(null)
const scheduleOpen = ref(false)
const editingSchedule = ref(null)
const previewOpen = ref(false)
const previewToken = ref(null)

const moduleConfigs = computed(() =>
  (props.configs || []).filter((c) => c.target_module === props.activeModule),
)
const modulePresets = computed(() =>
  (props.presets || []).filter((p) => p.target_module === props.activeModule),
)
const moduleSchedules = computed(() =>
  (props.schedules || []).filter((s) => (s.target_module || s.report_type) === props.activeModule),
)

function switchModule(key) {
  router.get(route('exports.index'), { module: key }, { preserveState: true, replace: true })
}

function openNewConfig() {
  editing.value = null
  editorMode.value = 'config'
  editorOpen.value = true
}

function openEditConfig(row) {
  editing.value = row
  editorMode.value = 'config'
  editorOpen.value = true
}

function openNewPreset() {
  editing.value = null
  editorMode.value = 'preset'
  editorOpen.value = true
}

function openEditPreset(row) {
  editing.value = row
  editorMode.value = 'preset'
  editorOpen.value = true
}

async function destroyConfig(row) {
  await axios.delete(route('exports.configs.destroy', row.id))
  router.reload({ only: ['configs'] })
}

async function destroyPreset(row) {
  await axios.delete(route('exports.presets.destroy', row.id))
  router.reload({ only: ['presets'] })
}

function openSchedule(row = null) {
  editingSchedule.value = row
  scheduleOpen.value = true
}

async function runNow(row) {
  await axios.post(route('exports.schedules.run-now', row.id))
  router.reload({ only: ['history', 'schedules'] })
}

async function destroySchedule(row) {
  await axios.delete(route('exports.schedules.destroy', row.id))
  router.reload({ only: ['schedules'] })
}

function onSaved() {
  router.reload({ only: ['configs', 'presets', 'schedules', 'availableColumns'] })
}

async function regenerate(token) {
  const pending = `pending-${Date.now()}`
  window.dispatchEvent(
    new CustomEvent('export-start', {
      detail: { token: pending, stableKey: pending, modalMessage: 'Regenerando…', status: 'queued', progress: 0 },
    }),
  )
  try {
    const { data } = await axios.post(route('exports.regenerate'), { token })
    window.dispatchEvent(
      new CustomEvent('export-pending-resolve', {
        detail: { pendingToken: pending, token: data.token, stableKey: pending },
      }),
    )
  } catch (e) {
    window.dispatchEvent(new CustomEvent('export-pending-fail', { detail: { pendingToken: pending } }))
  }
}

function openPreview(token) {
  previewToken.value = token
  previewOpen.value = true
}

async function quickExport() {
  await startExport({
    targetModule: props.activeModule,
    selectionMode: 'filter',
    filters: {},
    label: `Export ${props.activeModule}`,
  })
}
</script>

<template>
  <Head title="Exports" />
  <AuthenticatedLayout>
    <div class="py-8">
      <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Exports"
          description="Plantillas, presets, programados e historial de exportaciones XLSX."
        >
          <template #actions>
            <Button
              variant="outline"
              size="sm"
              @click="quickExport"
            >
              Exportar módulo
            </Button>
          </template>
        </PageHeader>

        <div class="flex flex-wrap gap-2">
          <button
            v-for="m in modules"
            :key="m.key"
            type="button"
            class="rounded-full px-3 py-1 text-xs font-medium"
            :class="activeModule === m.key ? 'bg-sky-600 text-white' : 'bg-slate-100 text-slate-700'"
            @click="switchModule(m.key)"
          >
            {{ m.label }}
          </button>
        </div>

        <div class="flex gap-4 border-b border-slate-200">
          <button
            type="button"
            class="border-b-2 px-1 pb-2 text-sm font-medium"
            :class="mainTab === 'config' ? 'border-sky-500 text-sky-700' : 'border-transparent text-slate-500'"
            @click="mainTab = 'config'"
          >
            Configuración
          </button>
          <button
            type="button"
            class="border-b-2 px-1 pb-2 text-sm font-medium"
            :class="mainTab === 'history' ? 'border-sky-500 text-sky-700' : 'border-transparent text-slate-500'"
            @click="mainTab = 'history'"
          >
            Historial
          </button>
        </div>

        <template v-if="mainTab === 'config'">
          <Card class="space-y-3 p-4">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold">
                Mis plantillas
              </h3>
              <Button
                size="sm"
                @click="openNewConfig"
              >
                Nueva
              </Button>
            </div>
            <ul class="divide-y divide-slate-100">
              <li
                v-for="c in moduleConfigs"
                :key="c.id"
                class="flex items-center justify-between py-2 text-sm"
              >
                <span>
                  {{ c.name }}
                  <Badge
                    v-if="c.is_default"
                    class="ml-2"
                  >
                    default
                  </Badge>
                </span>
                <span class="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    @click="openEditConfig(c)"
                  >
                    Editar
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    @click="destroyConfig(c)"
                  >
                    Borrar
                  </Button>
                </span>
              </li>
              <li
                v-if="!moduleConfigs.length"
                class="py-2 text-sm text-slate-500"
              >
                Sin plantillas para este módulo.
              </li>
            </ul>
          </Card>

          <Card class="space-y-3 p-4">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold">
                Presets admin
              </h3>
              <Button
                size="sm"
                variant="outline"
                @click="openNewPreset"
              >
                Nuevo preset
              </Button>
            </div>
            <ul class="divide-y divide-slate-100">
              <li
                v-for="p in modulePresets"
                :key="p.id"
                class="flex items-center justify-between py-2 text-sm"
              >
                <span>{{ p.name }}</span>
                <span class="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    @click="openEditPreset(p)"
                  >
                    Editar
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    @click="destroyPreset(p)"
                  >
                    Borrar
                  </Button>
                </span>
              </li>
            </ul>
          </Card>

          <Card class="space-y-3 p-4">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold">
                Programados
              </h3>
              <Button
                size="sm"
                @click="openSchedule(null)"
              >
                Nuevo
              </Button>
            </div>
            <ul class="divide-y divide-slate-100">
              <li
                v-for="s in moduleSchedules"
                :key="s.id"
                class="flex items-center justify-between py-2 text-sm"
              >
                <span>
                  {{ s.name }}
                  <span class="text-slate-500">· {{ s.schedule_type }}</span>
                </span>
                <span class="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    @click="runNow(s)"
                  >
                    Run now
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    @click="openSchedule(s)"
                  >
                    Editar
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    @click="destroySchedule(s)"
                  >
                    Borrar
                  </Button>
                </span>
              </li>
            </ul>
          </Card>
        </template>

        <Card
          v-else
          class="p-4"
        >
          <ul class="divide-y divide-slate-100">
            <li
              v-for="run in history?.data || []"
              :key="run.id"
              class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
            >
              <div>
                <p class="font-medium">
                  {{ run.target_module || run.report_type }}
                  <Badge class="ml-2">
                    {{ run.status }}
                  </Badge>
                </p>
                <p class="text-xs text-slate-500">
                  {{ run.token }} · {{ run.created_at }}
                </p>
              </div>
              <div class="flex gap-2">
                <a
                  v-if="run.status === 'completed' && run.token"
                  :href="route('exports.download', run.token)"
                  class="inline-flex h-8 items-center justify-center rounded-md border border-input bg-white px-3 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                >
                  Descargar
                </a>
                <Button
                  v-if="run.status === 'completed' && run.token"
                  size="sm"
                  variant="outline"
                  @click="openPreview(run.token)"
                >
                  Preview
                </Button>
                <Button
                  v-if="run.token"
                  size="sm"
                  variant="outline"
                  @click="regenerate(run.token)"
                >
                  Regenerar
                </Button>
              </div>
            </li>
          </ul>
        </Card>
      </div>
    </div>

    <ExportConfigEditor
      v-model:open="editorOpen"
      :target-module="activeModule"
      :available-columns="availableColumns"
      :initial="editing"
      :mode="editorMode"
      @saved="onSaved"
    />
    <ScheduledReportModal
      v-model:open="scheduleOpen"
      :target-module="activeModule"
      :initial="editingSchedule"
      :configs="moduleConfigs"
      :enabled-delivery-channels="enabledDeliveryChannels"
      @saved="onSaved"
    />
    <ExportPreviewSlideOver
      v-model:open="previewOpen"
      :token="previewToken"
    />
  </AuthenticatedLayout>
</template>
