<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import Dialog from '@/Components/ui/Dialog.vue'
import Button from '@/Components/ui/Button.vue'
import Input from '@/Components/ui/Input.vue'

const open = defineModel('open', { type: Boolean, default: false })
const props = defineProps({
  targetModule: { type: String, required: true },
  initial: { type: Object, default: null },
  configs: { type: Array, default: () => [] },
  enabledDeliveryChannels: { type: Array, default: () => ['email'] },
})
const emit = defineEmits(['saved'])

const form = ref({
  name: '',
  schedule_type: 'weekly',
  time: '08:00',
  day_of_week: 1,
  day_of_month: 1,
  period_type: 'this_week',
  last_x_days_value: 7,
  email: '',
  user_export_preference_id: null,
  is_active: true,
})
const saving = ref(false)

watch(
  () => [open.value, props.initial],
  () => {
    if (!open.value) return
    const i = props.initial
    form.value = {
      name: i?.name || '',
      schedule_type: i?.schedule_type || 'weekly',
      time: i?.schedule_config?.time || '08:00',
      day_of_week: i?.schedule_config?.day_of_week ?? 1,
      day_of_month: i?.schedule_config?.day_of_month ?? 1,
      period_type: i?.filters?.period_type || 'this_week',
      last_x_days_value: i?.filters?.last_x_days_value || 7,
      email: i?.delivery_channels?.[0]?.value || '',
      user_export_preference_id: i?.user_export_preference_id || props.configs[0]?.id || null,
      is_active: i?.is_active !== false,
    }
  },
)

async function save() {
  saving.value = true
  try {
    const payload = {
      name: form.value.name,
      target_module: props.targetModule,
      schedule_type: form.value.schedule_type,
      schedule_config: {
        time: form.value.time,
        day_of_week: form.value.day_of_week,
        day_of_month: form.value.day_of_month,
      },
      filters: {
        period_type: form.value.period_type,
        last_x_days_value: form.value.last_x_days_value,
      },
      delivery_channels: props.enabledDeliveryChannels.includes('email')
        ? [{ type: 'email', value: form.value.email }]
        : [],
      user_export_preference_id: form.value.user_export_preference_id,
      is_active: form.value.is_active,
    }
    if (props.initial?.id) {
      await axios.put(route('exports.schedules.update', props.initial.id), payload)
    } else {
      await axios.post(route('exports.schedules.store'), payload)
    }
    emit('saved')
    open.value = false
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:open="open"
    title="Reporte programado"
  >
    <div class="space-y-3 p-1 text-sm">
      <div>
        <label class="mb-1 block font-medium">Nombre</label>
        <Input v-model="form.name" />
      </div>
      <div>
        <label class="mb-1 block font-medium">Plantilla</label>
        <select
          v-model="form.user_export_preference_id"
          class="w-full rounded border-slate-300 text-sm"
        >
          <option
            v-for="c in configs"
            :key="c.id"
            :value="c.id"
          >
            {{ c.name }}
          </option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="mb-1 block font-medium">Frecuencia</label>
          <select
            v-model="form.schedule_type"
            class="w-full rounded border-slate-300 text-sm"
          >
            <option value="daily">
              Diaria
            </option>
            <option value="weekly">
              Semanal
            </option>
            <option value="monthly">
              Mensual
            </option>
          </select>
        </div>
        <div>
          <label class="mb-1 block font-medium">Hora</label>
          <Input
            v-model="form.time"
            type="time"
          />
        </div>
      </div>
      <div v-if="form.schedule_type === 'weekly'">
        <label class="mb-1 block font-medium">Día de la semana (0=Dom)</label>
        <Input
          v-model.number="form.day_of_week"
          type="number"
          min="0"
          max="6"
        />
      </div>
      <div v-if="form.schedule_type === 'monthly'">
        <label class="mb-1 block font-medium">Día del mes</label>
        <Input
          v-model.number="form.day_of_month"
          type="number"
          min="1"
          max="28"
        />
      </div>
      <div>
        <label class="mb-1 block font-medium">Periodo de datos</label>
        <select
          v-model="form.period_type"
          class="w-full rounded border-slate-300 text-sm"
        >
          <option value="this_week">
            Esta semana
          </option>
          <option value="this_month">
            Este mes
          </option>
          <option value="last_x_days">
            Últimos X días
          </option>
          <option value="custom">
            Custom (fechas en filtros)
          </option>
        </select>
      </div>
      <div v-if="form.period_type === 'last_x_days'">
        <label class="mb-1 block font-medium">X días</label>
        <Input
          v-model.number="form.last_x_days_value"
          type="number"
          min="1"
        />
      </div>
      <div v-if="enabledDeliveryChannels.includes('email')">
        <label class="mb-1 block font-medium">Email</label>
        <Input
          v-model="form.email"
          type="email"
        />
      </div>
      <p class="text-xs text-slate-500">
        Canales habilitados: {{ enabledDeliveryChannels.join(', ') }}.
        WhatsApp u otros se pueden activar después sin cambiar el pipeline.
      </p>
      <div class="flex justify-end gap-2 pt-2">
        <Button
          variant="outline"
          @click="open = false"
        >
          Cancelar
        </Button>
        <Button
          :disabled="saving || !form.name || !form.email"
          @click="save"
        >
          Guardar
        </Button>
      </div>
    </div>
  </Dialog>
</template>
