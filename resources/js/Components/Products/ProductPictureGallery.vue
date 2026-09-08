<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import Input from '@/Components/ui/Input.vue'
import { RefreshCw, Search } from 'lucide-vue-next'

const props = defineProps({
  groups: { type: Array, default: () => [] },
  needsResync: { type: Boolean, default: false },
  /** Si true, muestra acciones de quitar en grupo pending */
  allowRemovePending: { type: Boolean, default: false },
  resyncing: { type: Boolean, default: false },
})

const emit = defineEmits(['remove-pending', 'resync'])

const activeGroupKey = ref(null)
const activeUrl = ref(null)
const variantQuery = ref('')
const variantListRef = ref(null)

const safeGroups = computed(() =>
  (props.groups || []).filter((g) => Array.isArray(g.pictures) && g.pictures.length > 0),
)

const showVariantSearch = computed(() => safeGroups.value.length >= 8)

const activeGroup = computed(() => {
  if (!safeGroups.value.length) return null
  return (
    safeGroups.value.find((g) => g.key === activeGroupKey.value) || safeGroups.value[0]
  )
})

const activePictures = computed(() => activeGroup.value?.pictures || [])

function splitVariantLabel(label) {
  const raw = String(label || '').trim()
  if (!raw) return { section: 'Variantes', rowLabel: 'Variante' }
  const parts = raw.split(' · ').map((p) => p.trim()).filter(Boolean)
  if (parts.length >= 2) {
    return { section: parts[0], rowLabel: parts.slice(1).join(' · ') }
  }
  return { section: 'Variantes', rowLabel: raw }
}

function sectionTitleForGroup(group) {
  if (group.kind === 'shared') return 'Compartidas'
  if (group.kind === 'pending') return 'Pendientes'
  return splitVariantLabel(group.label).section
}

function rowLabelForGroup(group) {
  if (group.kind === 'shared' || group.kind === 'pending') {
    return group.label || (group.kind === 'shared' ? 'Compartidas' : 'Pendientes')
  }
  return splitVariantLabel(group.label).rowLabel
}

const filteredGroups = computed(() => {
  const q = variantQuery.value.trim().toLowerCase()
  if (!q) return safeGroups.value
  return safeGroups.value.filter((g) => {
    const hay = `${g.label || ''} ${g.sku || ''}`.toLowerCase()
    return hay.includes(q)
  })
})

const groupedVariants = computed(() => {
  const sections = []
  const indexByTitle = new Map()

  for (const group of filteredGroups.value) {
    const title = sectionTitleForGroup(group)
    let section = indexByTitle.get(title)
    if (!section) {
      section = { title, items: [] }
      indexByTitle.set(title, section)
      sections.push(section)
    }
    section.items.push({
      ...group,
      rowLabel: rowLabelForGroup(group),
    })
  }

  return sections
})

watch(
  safeGroups,
  (groups) => {
    if (!groups.length) {
      activeGroupKey.value = null
      activeUrl.value = null
      return
    }
    if (!groups.some((g) => g.key === activeGroupKey.value)) {
      activeGroupKey.value = groups[0].key
    }
    const pics = groups.find((g) => g.key === activeGroupKey.value)?.pictures || groups[0].pictures
    if (!pics.some((p) => p.url === activeUrl.value)) {
      activeUrl.value = pics[0]?.url || null
    }
  },
  { immediate: true, deep: true },
)

watch(activeGroupKey, async (key) => {
  if (!key) return
  await nextTick()
  const root = variantListRef.value
  if (!root) return
  const el = [...root.querySelectorAll('[data-variant-key]')].find(
    (node) => node.getAttribute('data-variant-key') === key,
  )
  el?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
})

function selectGroup(key) {
  activeGroupKey.value = key
  const group = safeGroups.value.find((g) => g.key === key)
  activeUrl.value = group?.pictures?.[0]?.url || null
}

function selectUrl(url) {
  activeUrl.value = url
}

const totalCount = computed(() =>
  safeGroups.value.reduce((sum, g) => sum + (g.pictures?.length || 0), 0),
)

const resyncHint =
  'Para separar fotos por variante hace falta actualizar desde Mercado Libre.'
</script>

<template>
  <div
    v-if="safeGroups.length"
    class="space-y-2"
  >
    <div
      v-if="needsResync"
      class="flex min-h-7 items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] text-amber-900"
    >
      <p
        class="min-w-0 flex-1 truncate"
        :title="resyncHint"
      >
        {{ resyncHint }}
      </p>
      <Button
        type="button"
        size="sm"
        variant="outline"
        class="h-6 shrink-0 gap-1 border-amber-300 bg-white px-2 text-[10px] text-amber-950 hover:bg-amber-100"
        :disabled="resyncing"
        @click="emit('resync')"
      >
        <RefreshCw
          class="size-3"
          :class="{ 'animate-spin': resyncing }"
        />
        {{ resyncing ? 'Actualizando…' : 'Actualizar' }}
      </Button>
    </div>

    <div class="flex flex-col gap-2 sm:grid sm:grid-cols-[minmax(0,1fr)_minmax(11rem,13rem)] sm:items-stretch sm:gap-2.5">
      <!-- Variantes: arriba en mobile, columna derecha en sm+ -->
      <aside
        class="order-1 flex max-h-40 min-h-0 flex-col overflow-hidden rounded-xl border border-slate-200/70 bg-white sm:order-2 sm:h-0 sm:max-h-none sm:min-h-full"
      >
        <div class="shrink-0 border-b border-slate-100 px-2.5 py-2">
          <div class="flex items-center justify-between gap-2">
            <p class="text-[11px] font-semibold text-slate-800">
              Variantes
            </p>
            <span class="rounded-full bg-slate-100 px-1.5 text-[10px] tabular-nums text-slate-500">
              {{ safeGroups.length }}
            </span>
          </div>
          <div
            v-if="showVariantSearch"
            class="relative mt-1.5"
          >
            <Search class="pointer-events-none absolute left-2 top-1/2 size-3 -translate-y-1/2 text-slate-400" />
            <Input
              v-model="variantQuery"
              type="search"
              placeholder="Color, talla…"
              class="h-7 rounded-lg border-slate-200 bg-slate-50/80 pl-7 text-[11px] shadow-none focus-visible:ring-1"
            />
          </div>
        </div>

        <div
          ref="variantListRef"
          class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-1.5"
        >
          <p
            v-if="!groupedVariants.length"
            class="px-2 py-4 text-center text-[11px] text-slate-400"
          >
            Sin coincidencias
          </p>

          <div
            v-for="section in groupedVariants"
            :key="section.title"
            class="mb-1 last:mb-0"
          >
            <p
              v-if="!(groupedVariants.length === 1 && section.title === 'Variantes')"
              class="sticky top-0 z-[1] bg-white/95 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 backdrop-blur-sm"
            >
              {{ section.title }}
            </p>
            <div class="space-y-0.5">
              <button
                v-for="item in section.items"
                :key="item.key"
                type="button"
                :data-variant-key="item.key"
                class="flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-left transition-colors"
                :class="
                  activeGroupKey === item.key
                    ? 'bg-slate-900 text-white'
                    : 'text-slate-700 hover:bg-slate-100'
                "
                :title="item.label"
                @click="selectGroup(item.key)"
              >
                <span class="min-w-0 flex-1 truncate text-[11px] font-medium">
                  {{ item.rowLabel }}
                </span>
                <span
                  class="shrink-0 rounded-full px-1.5 text-[10px] tabular-nums"
                  :class="
                    activeGroupKey === item.key
                      ? 'bg-white/20 text-white'
                      : 'bg-slate-100 text-slate-500'
                  "
                >
                  {{ item.pictures.length }}
                </span>
                <Badge
                  v-if="item.kind === 'pending'"
                  variant="warning"
                  class="h-4 shrink-0 px-1 text-[9px]"
                >
                  Pendiente
                </Badge>
              </button>
            </div>
          </div>
        </div>
      </aside>

      <!-- Preview -->
      <div class="order-2 min-w-0 overflow-hidden rounded-xl border border-slate-200/70 bg-slate-50/40 sm:order-1">
        <div class="relative aspect-[4/3] bg-white sm:aspect-[16/10]">
          <img
            v-if="activeUrl"
            :src="activeUrl"
            alt=""
            referrerpolicy="no-referrer"
            class="size-full object-contain"
          >
          <div
            v-if="activeGroup?.kind === 'pending'"
            class="absolute left-2 top-2"
          >
            <Badge
              variant="warning"
              class="text-[10px]"
            >
              Pendiente de publicar
            </Badge>
          </div>
          <div class="absolute bottom-2 right-2 rounded-full bg-black/55 px-2 py-0.5 text-[10px] text-white">
            {{ totalCount }} foto{{ totalCount === 1 ? '' : 's' }}
          </div>
        </div>

        <div
          v-if="activePictures.length > 1"
          class="flex gap-1.5 overflow-x-auto border-t border-slate-100 bg-white p-2"
        >
          <div
            v-for="(pic, idx) in activePictures"
            :key="pic.id || pic.url || idx"
            class="relative size-14 shrink-0"
          >
            <button
              type="button"
              class="size-full overflow-hidden rounded-md ring-1 transition"
              :class="
                activeUrl === pic.url
                  ? 'ring-2 ring-slate-900'
                  : 'ring-slate-200 hover:ring-slate-400'
              "
              @click="selectUrl(pic.url)"
            >
              <img
                :src="pic.url"
                alt=""
                referrerpolicy="no-referrer"
                loading="lazy"
                class="size-full object-cover"
              >
            </button>
            <button
              v-if="allowRemovePending && activeGroup?.kind === 'pending' && pic.id"
              type="button"
              class="absolute right-0.5 top-0.5 z-10 flex size-5 items-center justify-center rounded-full bg-white/95 text-[11px] font-semibold text-slate-700 shadow"
              title="Quitar"
              @click="emit('remove-pending', pic.id)"
            >
              ×
            </button>
          </div>
        </div>
      </div>
    </div>

    <p
      v-if="activeGroup?.sku"
      class="text-[10px] text-muted-foreground"
    >
      SKU {{ activeGroup.sku }}
    </p>
  </div>

  <div
    v-else
    class="rounded-xl border border-dashed border-slate-200 px-3 py-6 text-center text-[12px] text-muted-foreground"
  >
    Sin fotos para mostrar.
    <slot name="empty-action" />
  </div>
</template>
