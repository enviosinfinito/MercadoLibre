<template>
  <FilterDateRangeField
    v-model="dateRange"
    :label="label"
    :show-icon="showIcon"
    :show-hint="showHint"
    :show-presets="showPresets"
    class="sm:col-span-2"
    @clear="clearDateRange"
  />
</template>

<script setup>
import { ref, watch } from 'vue'
import FilterDateRangeField from '@/Components/ui/FilterDateRangeField.vue'
import {
  dateRangeFromFilterStrings,
  filterStringsFromDateRange,
  isSameDateRange,
} from '@/utils/filterDates'

const props = defineProps({
  filterObject: { type: Object, required: true },
  startKey: { type: String, default: 'date_from' },
  endKey: { type: String, default: 'date_to' },
  label: { type: String, default: 'Período' },
  showIcon: { type: Boolean, default: true },
  showHint: { type: Boolean, default: true },
  showPresets: { type: Boolean, default: true },
})

const dateRange = ref(null)

function syncFromObject() {
  const next = dateRangeFromFilterStrings(
    props.filterObject[props.startKey],
    props.filterObject[props.endKey],
  )
  if (!isSameDateRange(dateRange.value, next)) {
    dateRange.value = next
  }
}

function applyToObject(range) {
  const { start, end } = filterStringsFromDateRange(range)
  if (props.filterObject[props.startKey] === start && props.filterObject[props.endKey] === end) {
    return
  }
  props.filterObject[props.startKey] = start
  props.filterObject[props.endKey] = end
}

function clearDateRange() {
  dateRange.value = null
  applyToObject(null)
}

watch(
  () => [props.filterObject[props.startKey], props.filterObject[props.endKey]],
  syncFromObject,
  { immediate: true },
)

watch(dateRange, (range) => {
  applyToObject(range)
}, { deep: true })
</script>
