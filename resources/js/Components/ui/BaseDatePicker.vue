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
      class="absolute z-40 mt-1 w-[min(100%,20rem)] rounded-xl border border-neutral-200 bg-white p-3 shadow-lg"
    >
      <CalendarRoot
        v-slot="{ weekDays, grid }"
        v-model="calendarValue"
        class="w-full"
        locale="es-MX"
        @update:model-value="onPick"
      >
        <CalendarHeader class="mb-2 flex items-center justify-between">
          <CalendarPrev class="rounded p-1 hover:bg-neutral-100" />
          <CalendarHeading class="text-sm font-semibold text-neutral-800" />
          <CalendarNext class="rounded p-1 hover:bg-neutral-100" />
        </CalendarHeader>
        <div v-for="month in grid" :key="month.value.toString()" class="w-full">
          <CalendarGrid class="w-full border-collapse">
            <CalendarGridHead>
              <CalendarGridRow class="flex w-full">
                <CalendarHeadCell
                  v-for="day in weekDays"
                  :key="day"
                  class="w-8 text-center text-[10px] font-medium text-neutral-400"
                >
                  {{ day }}
                </CalendarHeadCell>
              </CalendarGridRow>
            </CalendarGridHead>
            <CalendarGridBody>
              <CalendarGridRow
                v-for="(weekDates, index) in month.rows"
                :key="index"
                class="flex w-full"
              >
                <CalendarCell
                  v-for="weekDate in weekDates"
                  :key="weekDate.toString()"
                  :date="weekDate"
                  class="relative p-0"
                >
                  <CalendarCellTrigger
                    :day="weekDate"
                    :month="month.value"
                    class="flex h-8 w-8 items-center justify-center rounded-md text-sm text-neutral-700 hover:bg-neutral-100 data-[selected]:bg-[var(--brand-primary)] data-[selected]:text-white data-[outside-view]:text-neutral-300"
                  />
                </CalendarCell>
              </CalendarGridRow>
            </CalendarGridBody>
          </CalendarGrid>
        </div>
      </CalendarRoot>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { CalendarDate, parseDate } from '@internationalized/date'
import {
  CalendarCell,
  CalendarCellTrigger,
  CalendarGrid,
  CalendarGridBody,
  CalendarGridHead,
  CalendarGridRow,
  CalendarHeadCell,
  CalendarHeader,
  CalendarHeading,
  CalendarNext,
  CalendarPrev,
  CalendarRoot,
} from 'reka-ui'
import { Calendar } from 'lucide-vue-next'
import { formatDateYmd, parseFilterDate } from '@/utils/filterDates'
import './base-datepicker.css'

const props = defineProps({
  modelValue: { type: [Date, String, null], default: null },
  placeholder: { type: String, default: 'Seleccionar fecha' },
})

const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const calendarValue = ref(undefined)

function toCalendarDate(value) {
  if (!value) return undefined
  if (typeof value === 'string') {
    try {
      return parseDate(value.slice(0, 10))
    } catch {
      const d = parseFilterDate(value)
      if (!d) return undefined
      return new CalendarDate(d.getFullYear(), d.getMonth() + 1, d.getDate())
    }
  }
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    return new CalendarDate(value.getFullYear(), value.getMonth() + 1, value.getDate())
  }
  return undefined
}

function fromCalendarDate(value) {
  if (!value) return null
  return new Date(value.year, value.month - 1, value.day)
}

watch(
  () => props.modelValue,
  (v) => {
    calendarValue.value = toCalendarDate(v)
  },
  { immediate: true },
)

const displayText = computed(() => {
  if (!props.modelValue) return ''
  if (typeof props.modelValue === 'string') return props.modelValue.slice(0, 10)
  return formatDateYmd(props.modelValue)
})

function onPick(value) {
  emit('update:modelValue', fromCalendarDate(value))
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
