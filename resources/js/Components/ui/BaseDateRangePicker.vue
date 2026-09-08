<template>
  <div class="base-datepicker relative w-full">
    <button
      type="button"
      class="base-datepicker__trigger flex w-full items-center gap-2 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-left text-sm text-neutral-800 shadow-sm hover:border-neutral-300 focus:outline-none focus:ring-2 focus:ring-[rgb(var(--brand-primary-rgb)/0.2)]"
      @click="open = !open"
    >
      <Calendar class="h-4 w-4 shrink-0 text-neutral-400" aria-hidden="true" />
      <span class="min-w-0 flex-1 truncate" :class="displayText ? 'text-neutral-900' : 'text-neutral-400'">
        {{ displayText || placeholder }}
      </span>
    </button>

    <div
      v-if="open"
      class="absolute z-40 mt-1 w-[min(100%,22rem)] rounded-xl border border-neutral-200 bg-white p-3 shadow-lg"
    >
      <p v-if="showHint" class="mb-2 text-[11px] text-neutral-500">
        Selecciona inicio y fin del período
      </p>
      <RangeCalendarRoot
        v-slot="{ weekDays, grid }"
        v-model="rangeValue"
        class="w-full"
        locale="es-MX"
        @update:model-value="onPick"
      >
        <RangeCalendarHeader class="mb-2 flex items-center justify-between">
          <RangeCalendarPrev class="rounded p-1 hover:bg-neutral-100" />
          <RangeCalendarHeading class="text-sm font-semibold text-neutral-800" />
          <RangeCalendarNext class="rounded p-1 hover:bg-neutral-100" />
        </RangeCalendarHeader>
        <div v-for="month in grid" :key="month.value.toString()" class="w-full">
          <RangeCalendarGrid class="w-full border-collapse">
            <RangeCalendarGridHead>
              <RangeCalendarGridRow class="flex w-full">
                <RangeCalendarHeadCell
                  v-for="day in weekDays"
                  :key="day"
                  class="w-8 text-center text-[10px] font-medium text-neutral-400"
                >
                  {{ day }}
                </RangeCalendarHeadCell>
              </RangeCalendarGridRow>
            </RangeCalendarGridHead>
            <RangeCalendarGridBody>
              <RangeCalendarGridRow
                v-for="(weekDates, index) in month.rows"
                :key="index"
                class="flex w-full"
              >
                <RangeCalendarCell
                  v-for="weekDate in weekDates"
                  :key="weekDate.toString()"
                  :date="weekDate"
                  class="relative p-0"
                >
                  <RangeCalendarCellTrigger
                    :day="weekDate"
                    :month="month.value"
                    class="flex h-8 w-8 items-center justify-center rounded-md text-sm text-neutral-700 hover:bg-neutral-100 data-[selected]:bg-[var(--brand-primary)] data-[selected]:text-white data-[selection-start]:rounded-l-md data-[selection-end]:rounded-r-md data-[highlighted]:bg-[var(--brand-muted)] data-[outside-view]:text-neutral-300"
                  />
                </RangeCalendarCell>
              </RangeCalendarGridRow>
            </RangeCalendarGridBody>
          </RangeCalendarGrid>
        </div>
      </RangeCalendarRoot>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { CalendarDate } from '@internationalized/date'
import {
  RangeCalendarCell,
  RangeCalendarCellTrigger,
  RangeCalendarGrid,
  RangeCalendarGridBody,
  RangeCalendarGridHead,
  RangeCalendarGridRow,
  RangeCalendarHeadCell,
  RangeCalendarHeader,
  RangeCalendarHeading,
  RangeCalendarNext,
  RangeCalendarPrev,
  RangeCalendarRoot,
} from 'reka-ui'
import { Calendar } from 'lucide-vue-next'
import { formatDateYmd, parseFilterDate } from '@/utils/filterDates'
import './base-datepicker.css'

const props = defineProps({
  /** @type {[Date, Date] | null} */
  modelValue: { type: Array, default: null },
  placeholder: { type: String, default: 'Inicio - Fin' },
  showHint: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const rangeValue = ref({ start: undefined, end: undefined })

function toCalendarDate(value) {
  if (!value) return undefined
  const d = value instanceof Date ? value : parseFilterDate(value)
  if (!d || Number.isNaN(d.getTime())) return undefined
  return new CalendarDate(d.getFullYear(), d.getMonth() + 1, d.getDate())
}

function fromCalendarDate(value) {
  if (!value) return null
  return new Date(value.year, value.month - 1, value.day)
}

function syncFromProps(v) {
  if (!Array.isArray(v) || !v[0] || !v[1]) {
    rangeValue.value = { start: undefined, end: undefined }
    return
  }
  rangeValue.value = {
    start: toCalendarDate(v[0]),
    end: toCalendarDate(v[1]),
  }
}

watch(() => props.modelValue, syncFromProps, { immediate: true, deep: true })

const displayText = computed(() => {
  if (!Array.isArray(props.modelValue) || !props.modelValue[0] || !props.modelValue[1]) return ''
  return `${formatDateYmd(props.modelValue[0])} – ${formatDateYmd(props.modelValue[1])}`
})

function onPick(value) {
  if (!value?.start || !value?.end) return
  emit('update:modelValue', [fromCalendarDate(value.start), fromCalendarDate(value.end)])
  open.value = false
}

function onDocClick(e) {
  if (!open.value) return
  const root = e.target?.closest?.('.base-datepicker')
  if (!root) open.value = false
}

onMounted(() => document.addEventListener('click', onDocClick))
onUnmounted(() => document.removeEventListener('click', onDocClick))
</script>
