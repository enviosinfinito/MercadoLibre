<script setup lang="ts">
import { computed } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import AdsRoasInsight from '@/Components/Ads/AdsRoasInsight.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'

const props = defineProps<{
  show: boolean
  insight: Record<string, any> | null | undefined
  currency?: string
  itemLabel?: string | null
}>()

const emit = defineEmits<{
  close: []
  'focus-chart': []
}>()

const currency = computed(() => props.currency || 'MXN')

const zoneLabel = computed(() => {
  const zone = props.insight?.zone
  if (zone === 'above') return 'En objetivo'
  if (zone === 'near') return 'Casi'
  if (zone === 'below') return 'Bajo objetivo'
  return null
})

function onClose() {
  emit('close')
}

function onFocusChart() {
  emit('focus-chart')
  emit('close')
}
</script>

<template>
  <SlideOverShell
    :show="props.show"
    title="¿Cuánto te rinden los ads?"
    stackable
    accessibility-title="Rendimiento de ads explicado"
    @close="onClose"
  >
    <template #header>
      <div class="min-w-0 pr-2">
        <p class="truncate text-sm font-semibold text-slate-900">¿Cuánto te rinden los ads?</p>
        <p v-if="itemLabel" class="truncate text-[11px] text-slate-500">{{ itemLabel }}</p>
      </div>
    </template>

    <template v-if="zoneLabel" #header-actions>
      <span
        class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
        :class="{
          'bg-emerald-100 text-emerald-900': insight?.zone === 'above',
          'bg-amber-100 text-amber-900': insight?.zone === 'near',
          'bg-rose-100 text-rose-900': insight?.zone === 'below',
        }"
      >
        {{ zoneLabel }}
      </span>
    </template>

    <div class="overflow-y-auto p-4">
      <AdsRoasInsight
        v-if="insight"
        :insight="insight"
        :currency="currency"
        embedded
        @focus-chart="onFocusChart"
      />
      <p v-else class="py-8 text-center text-sm text-slate-500">
        Sin datos de ROAS para este ítem.
      </p>
    </div>

    <template #footer>
      <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
        <template #end>
          <ActionGroup>
            <ActionButton variant="secondary" @click="onClose">Cerrar</ActionButton>
            <ActionButton variant="primary" @click="onFocusChart">
              Ver evolución ROAS
            </ActionButton>
          </ActionGroup>
        </template>
      </ActionBar>
    </template>
  </SlideOverShell>
</template>
