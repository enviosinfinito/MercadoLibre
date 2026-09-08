<script setup>
import { computed, ref, watch } from 'vue'
import draggable from 'vuedraggable'
import axios from 'axios'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import Button from '@/Components/ui/Button.vue'
import Input from '@/Components/ui/Input.vue'

const open = defineModel('open', { type: Boolean, default: false })
const props = defineProps({
  targetModule: { type: String, required: true },
  availableColumns: { type: Array, default: () => [] },
  initial: { type: Object, default: null },
  mode: { type: String, default: 'config' }, // config | preset
})
const emit = defineEmits(['saved'])

const tab = ref('general')
const name = ref('')
const isDefault = ref(false)
const isActive = ref(true)
const included = ref([])
const search = ref('')
const formulaName = ref('')
const formulaExpression = ref('')
const saving = ref(false)
const dirty = ref(false)

watch(
  () => [open.value, props.initial],
  () => {
    if (!open.value) return
    dirty.value = false
    name.value = props.initial?.name || ''
    isDefault.value = Boolean(props.initial?.is_default)
    isActive.value = props.initial?.is_active !== false
    const cols = props.initial?.columns || []
    const formulas = props.initial?.formula_columns || []
    const formulaMap = Object.fromEntries(formulas.map((f) => [f.key, f]))
    included.value = cols.map((key) => {
      if (String(key).startsWith('formula__')) {
        return {
          key,
          label: formulaMap[key]?.name || key,
          kind: 'formula',
          expression: formulaMap[key]?.expression || '',
        }
      }
      const col = props.availableColumns.find((c) => c.key === key)
      return { key, label: col?.label || key, kind: 'column' }
    })
  },
)

const availableFiltered = computed(() => {
  const q = search.value.trim().toLowerCase()
  const used = new Set(included.value.map((i) => i.key))
  return props.availableColumns.filter((c) => {
    if (used.has(c.key)) return false
    if (!q) return true
    return c.key.includes(q) || c.label.toLowerCase().includes(q)
  })
})

function markDirty() {
  dirty.value = true
}

function addColumn(col) {
  included.value.push({ key: col.key, label: col.label, kind: 'column' })
  markDirty()
}

function removeAt(index) {
  included.value.splice(index, 1)
  markDirty()
}

function addFormula() {
  const n = formulaName.value.trim()
  const expr = formulaExpression.value.trim()
  if (!n || !expr) return
  const slug = n.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
  const key = `formula__${slug}`
  included.value.push({ key, label: n, kind: 'formula', expression: expr })
  formulaName.value = ''
  formulaExpression.value = ''
  markDirty()
}

async function save() {
  saving.value = true
  try {
    const payload = {
      target_module: props.targetModule,
      name: name.value,
      columns: included.value.map((i) => i.key),
      formula_columns: included.value
        .filter((i) => i.kind === 'formula')
        .map((i) => ({ key: i.key, name: i.label, expression: i.expression })),
      is_default: isDefault.value,
      is_active: isActive.value,
    }
    if (props.mode === 'preset') {
      if (props.initial?.id) {
        await axios.put(route('exports.presets.update', props.initial.id), payload)
      } else {
        await axios.post(route('exports.presets.store'), payload)
      }
    } else if (props.initial?.id) {
      await axios.put(route('exports.configs.update', props.initial.id), payload)
    } else {
      await axios.post(route('exports.configs.store'), payload)
    }
    dirty.value = false
    emit('saved')
    open.value = false
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SlideOverShell
    :show="open"
    :title="initial?.id ? 'Editar plantilla' : 'Nueva plantilla'"
    :unsaved-changes="dirty"
    accessibility-title="Editor de plantilla de export"
    @close="open = false"
  >
    <div class="flex min-h-0 flex-1 flex-col">
      <div class="flex gap-2 border-b border-slate-200 px-4 pt-3">
        <button
          type="button"
          class="border-b-2 px-2 pb-2 text-sm"
          :class="tab === 'general' ? 'border-sky-500 text-sky-700' : 'border-transparent text-slate-500'"
          @click="tab = 'general'"
        >
          General
        </button>
        <button
          type="button"
          class="border-b-2 px-2 pb-2 text-sm"
          :class="tab === 'columns' ? 'border-sky-500 text-sky-700' : 'border-transparent text-slate-500'"
          @click="tab = 'columns'"
        >
          Columnas
        </button>
      </div>

      <div class="min-h-0 flex-1 overflow-y-auto p-4">
        <div
          v-if="tab === 'general'"
          class="space-y-4"
        >
          <div>
            <label class="mb-1 block text-sm font-medium">Nombre</label>
            <Input
              v-model="name"
              @input="markDirty"
            />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input
              v-model="isDefault"
              type="checkbox"
              @change="markDirty"
            >
            Usar como predeterminada
          </label>
          <label
            v-if="mode === 'preset'"
            class="flex items-center gap-2 text-sm"
          >
            <input
              v-model="isActive"
              type="checkbox"
              @change="markDirty"
            >
            Activo
          </label>
        </div>

        <div
          v-else
          class="grid gap-4 md:grid-cols-2"
        >
          <div>
            <Input
              v-model="search"
              placeholder="Buscar columnas…"
              class="mb-2"
            />
            <ul class="max-h-80 space-y-1 overflow-y-auto rounded border border-slate-200 p-2">
              <li
                v-for="col in availableFiltered"
                :key="col.key"
                class="flex items-center justify-between gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50"
              >
                <span>{{ col.label }}</span>
                <Button
                  size="sm"
                  variant="outline"
                  @click="addColumn(col)"
                >
                  +
                </Button>
              </li>
            </ul>
            <div class="mt-4 space-y-2 rounded border border-dashed border-slate-300 p-3">
              <p class="text-sm font-medium">
                Nueva fórmula
              </p>
              <Input
                v-model="formulaName"
                placeholder="Nombre (ej. Margen)"
              />
              <Input
                v-model="formulaExpression"
                placeholder="{{total_amount}} * 0.1"
              />
              <Button
                size="sm"
                variant="outline"
                @click="addFormula"
              >
                Agregar fórmula
              </Button>
            </div>
          </div>
          <div>
            <p class="mb-2 text-sm font-medium">
              Incluidas (arrastrar para ordenar)
            </p>
            <draggable
              v-model="included"
              item-key="key"
              class="max-h-[28rem] space-y-1 overflow-y-auto rounded border border-slate-200 p-2"
              @change="markDirty"
            >
              <template #item="{ element, index }">
                <div class="flex items-center justify-between gap-2 rounded bg-slate-50 px-2 py-1.5 text-sm">
                  <span>
                    <span
                      v-if="element.kind === 'formula'"
                      class="mr-1 text-violet-600"
                    >ƒ</span>
                    {{ element.label }}
                  </span>
                  <button
                    type="button"
                    class="text-rose-500"
                    @click="removeAt(index)"
                  >
                    ×
                  </button>
                </div>
              </template>
            </draggable>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <ActionBar
        preset="slide"
        position="sticky-bottom"
        :show-informative-pages="false"
      >
        <template #end>
          <ActionGroup>
            <ActionButton
              label="Cancelar"
              variant="secondary"
              @click="open = false"
            />
            <ActionButton
              label="Guardar"
              loading-label="Guardando…"
              variant="brand"
              :loading="saving"
              :disabled="!name || !included.length"
              @click="save"
            />
          </ActionGroup>
        </template>
      </ActionBar>
    </template>
  </SlideOverShell>
</template>
