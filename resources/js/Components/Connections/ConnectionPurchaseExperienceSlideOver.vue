<script setup>
import { computed } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import Badge from '@/Components/ui/Badge.vue'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'

const props = defineProps({
  show: { type: Boolean, default: false },
  connection: { type: Object, default: null },
  purchaseExperienceSummary: {
    type: Object,
    default: () => ({ enabled: false, totals_by_color: {}, without_data: 0, top_problems: [] }),
  },
  purchaseExperienceListings: { type: Array, default: () => [] },
})

const emit = defineEmits(['close'])

const title = computed(
  () => props.connection?.display_name || props.connection?.external_user_id || 'Experiencia',
)

const summary = computed(() => props.purchaseExperienceSummary ?? {})

const peColorEntries = computed(() => {
  const order = ['green', 'light_green', 'yellow', 'orange', 'red']
  const entries = Object.entries(summary.value.totals_by_color ?? {}).map(([color, count]) => ({
    color,
    count: Number(count),
  }))
  entries.sort((a, b) => {
    const ai = order.indexOf(a.color)
    const bi = order.indexOf(b.color)
    return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi)
  })
  return entries
})

const peBadgeVariant = (color) => {
  if (!color) return 'muted'
  if (String(color).includes('green')) return 'success'
  if (String(color).includes('yellow') || String(color).includes('orange')) return 'warning'
  if (String(color).includes('red')) return 'danger'
  return 'muted'
}
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    compact-header
    fill-height
    mobile-full-bleed
    stackable
    :accessibility-title="`Experiencia · ${title}`"
    @close="emit('close')"
  >
    <template #header>
      <p class="truncate text-[13px] font-semibold tracking-tight text-slate-900">
        Experiencia · {{ title }}
      </p>
    </template>

    <div class="space-y-4 px-4 py-3 sm:px-5">
      <p
        v-if="!summary.enabled"
        class="rounded-lg border border-dashed border-slate-200 bg-slate-50/80 px-3 py-2.5 text-[11px] leading-relaxed text-slate-500"
      >
        Activa <span class="font-medium text-slate-700">Experiencia de compra</span> en el sync de
        publicaciones para traer score, problemas y remedies por ítem.
      </p>

      <template v-else>
        <section class="space-y-2">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Resumen</h3>
          <div class="flex flex-wrap gap-1.5">
            <Badge
              v-for="entry in peColorEntries"
              :key="entry.color"
              :variant="peBadgeVariant(entry.color)"
              class="h-5 rounded-full px-1.5 text-[10px] capitalize"
            >
              {{ entry.color }} · {{ entry.count }}
            </Badge>
            <Badge
              v-if="summary.without_data"
              variant="secondary"
              class="h-5 rounded-full px-1.5 text-[10px]"
            >
              sin dato · {{ summary.without_data }}
            </Badge>
          </div>
        </section>

        <section v-if="summary.top_problems?.length" class="space-y-2">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            Principales problemas
          </h3>
          <ul class="space-y-1.5">
            <li
              v-for="problem in summary.top_problems"
              :key="problem.key"
              class="flex items-start justify-between gap-3 rounded-md border border-slate-100 bg-slate-50/50 px-2.5 py-2 text-[11px]"
            >
              <span class="min-w-0 text-slate-700">{{ problem.title }}</span>
              <span class="shrink-0 tabular-nums font-semibold text-slate-900">{{ problem.count }}</span>
            </li>
          </ul>
        </section>

        <section class="space-y-2">
          <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            Publicaciones en riesgo
          </h3>
          <p
            v-if="!purchaseExperienceListings.length"
            class="text-[11px] text-slate-500"
          >
            No hay publicaciones yellow/orange/red sincronizadas.
          </p>
          <ul v-else class="space-y-1.5">
            <li
              v-for="listing in purchaseExperienceListings"
              :key="listing.id"
              class="rounded-md border border-slate-100 px-2.5 py-2"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-[12px] font-medium text-slate-900">
                    {{ listing.title || listing.external_item_id }}
                  </p>
                  <p class="font-mono text-[10px] text-slate-400">{{ listing.external_item_id }}</p>
                </div>
                <Badge
                  :variant="peBadgeVariant(listing.pe_color)"
                  class="h-5 shrink-0 rounded-full px-1.5 text-[10px] capitalize"
                >
                  {{ listing.pe_color }}{{ listing.pe_value != null ? ` · ${listing.pe_value}` : '' }}
                </Badge>
              </div>
              <a
                v-if="listing.permalink"
                :href="listing.permalink"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-1 inline-block text-[10px] font-medium text-brand hover:underline"
              >
                Ver en ML
              </a>
            </li>
          </ul>
        </section>
      </template>
    </div>

    <template #footer>
      <ActionBar preset="slide" position="sticky-bottom" :show-informative-pages="false">
        <ActionGroup align="end">
          <ActionButton variant="outline" @click="emit('close')">Cerrar</ActionButton>
        </ActionGroup>
      </ActionBar>
    </template>
  </SlideOverShell>
</template>
