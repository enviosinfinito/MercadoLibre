<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import MoneyText from '@/Components/App/MoneyText.vue'
import ProductPictureGallery from '@/Components/Products/ProductPictureGallery.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { toCodeCase } from '@/lib/formatDisplayText'
import { ExternalLink } from 'lucide-vue-next'

const props = defineProps<{
  listingId: number | null
}>()

const loading = ref(false)
const error = ref<string | null>(null)
const listing = ref<Record<string, any> | null>(null)
const statusBusy = ref(false)
const resyncing = ref(false)

function normalizeListingPicture(raw: any, idx: number) {
  if (typeof raw === 'string') {
    return { id: null as string | null, url: raw }
  }
  const url = raw?.secure_url || raw?.url || raw?.source || null
  if (!url) return null
  const id = raw?.id != null ? String(raw.id) : null
  return { id, url, size: raw?.size ?? null }
}

function variantPictureLabel(v: any) {
  const attrs = v?.attribute_combinations
  if (Array.isArray(attrs) && attrs.length) {
    const parts = attrs
      .map((a: any) => a?.value_name)
      .filter((x: any) => typeof x === 'string' && x.trim())
    if (parts.length) return parts.join(' · ')
  }
  const name = v?.variant_name
  if (typeof name === 'string' && name && name.toLowerCase() !== 'default') return name
  const sku = v?.variant_sku || v?.sku_external
  return typeof sku === 'string' && sku ? sku : 'Variante'
}

function attributeCombinationsLabel(attrs: Array<{ value_name?: string; name?: string }> | null | undefined) {
  if (!Array.isArray(attrs) || !attrs.length) return ''
  return attrs
    .map((a) => a.value_name || a.name)
    .filter(Boolean)
    .join(' · ')
}

const pictureGallery = computed(() => {
  const rawPics = Array.isArray(listing.value?.pictures) ? listing.value.pictures : []
  const byId: Record<string, { id: string | null; url: string }> = {}
  for (const [idx, raw] of rawPics.entries()) {
    const pic = normalizeListingPicture(raw, idx)
    if (!pic) continue
    const key = pic.id || pic.url
    byId[key] = pic
  }

  const variants = Array.isArray(listing.value?.variants) ? listing.value.variants : []
  const assigned = new Set<string>()
  const groups: Array<Record<string, any>> = []
  let hasPictureIds = false

  for (const v of variants) {
    const ids = Array.isArray(v?.picture_ids) ? v.picture_ids : []
    if (!ids.length) continue
    hasPictureIds = true
    const pics = []
    for (const pictureId of ids) {
      const pid = String(pictureId)
      const pic = byId[pid]
      if (!pic) continue
      assigned.add(pid)
      pics.push(pic)
    }
    if (!pics.length) continue
    groups.push({
      key: `variant-${v.id}`,
      kind: 'variant',
      label: variantPictureLabel(v),
      sku: v.variant_sku || v.sku_external || null,
      pictures: pics,
    })
  }

  const shared = Object.entries(byId)
    .filter(([key, pic]) => !assigned.has(pic.id || key))
    .map(([, pic]) => pic)

  if (shared.length) {
    groups.push({
      key: 'shared',
      kind: 'shared',
      label: hasPictureIds ? 'Otras de la publicación' : 'Publicación',
      sku: null,
      pictures: shared,
    })
  }

  return {
    groups,
    needs_resync: variants.length > 1 && !hasPictureIds && Object.keys(byId).length > 0,
  }
})

const attributes = computed(() => {
  const meta = listing.value?.attributes_meta
  if (!meta) return []
  if (Array.isArray(meta)) {
    return meta
      .map((a) => ({
        name: a?.name || a?.id || 'Atributo',
        value: a?.value_name || a?.value_id || a?.value || '—',
      }))
      .slice(0, 40)
  }
  if (typeof meta === 'object') {
    return Object.entries(meta)
      .slice(0, 40)
      .map(([name, value]) => ({
        name,
        value: typeof value === 'object' ? JSON.stringify(value) : String(value ?? '—'),
      }))
  }
  return []
})

const variants = computed(() => (Array.isArray(listing.value?.variants) ? listing.value.variants : []))

const pricedVariants = computed(() =>
  variants.value.filter((v: any) => v?.price_amount != null && !Number.isNaN(Number(v.price_amount))),
)

const priceSummary = computed(() => {
  if (!pricedVariants.value.length) return null
  const amounts = pricedVariants.value.map((v: any) => Number(v.price_amount))
  const currency = pricedVariants.value[0]?.currency_code || 'MXN'
  const min = Math.min(...amounts)
  const max = Math.max(...amounts)
  return { min, max, currency, isRange: min !== max }
})

const totalStock = computed(() => {
  if (!variants.value.length) return null
  let sum = 0
  let hasAny = false
  for (const v of variants.value) {
    if (v?.available_quantity != null && !Number.isNaN(Number(v.available_quantity))) {
      sum += Number(v.available_quantity)
      hasAny = true
    }
  }
  return hasAny ? sum : null
})

const formattedDescription = computed(() => {
  const raw = listing.value?.description
  if (typeof raw !== 'string' || !raw.trim()) return ''
  return formatChannelDescription(raw)
})

function formatChannelDescription(raw: string) {
  return raw
    .replace(/\\n/g, '\n')
    .replace(/\\t/g, '\t')
    .trim()
}

async function load() {
  if (!props.listingId) {
    listing.value = null
    error.value = null
    return
  }
  loading.value = true
  error.value = null
  try {
    const data = await fetchSlidePayload(route('publications.show', props.listingId), {
      cache: 'no-store',
    })
    listing.value = data.listing
  } catch (e: any) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar la publicación.'
    listing.value = null
  } finally {
    loading.value = false
  }
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

async function setStatus(next: 'active' | 'paused') {
  if (!listing.value?.id || statusBusy.value) return
  statusBusy.value = true
  try {
    const res = await fetch(route('publications.update', listing.value.id), {
      method: 'PUT',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({ status: next }),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || `Error ${res.status}`)
    listing.value = { ...listing.value, status: next }
  } catch (e: any) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo actualizar el estado.'
  } finally {
    statusBusy.value = false
  }
}

async function resyncListing() {
  if (!listing.value?.id || resyncing.value) return
  resyncing.value = true
  error.value = null
  try {
    const res = await fetch(route('publications.sync-now', listing.value.id), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
      credentials: 'same-origin',
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || `Error ${res.status}`)
    if (data.listing) {
      listing.value = data.listing
    } else {
      await load()
    }
  } catch (e: any) {
    error.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo actualizar la publicación.'
  } finally {
    resyncing.value = false
  }
}

watch(
  () => props.listingId,
  () => {
    void load()
  },
  { immediate: true },
)

defineExpose({ reload: load })
</script>

<template>
  <div class="space-y-4 p-3 sm:p-4">
    <div
      v-if="loading"
      class="py-16 text-center text-sm text-muted-foreground"
    >
      Cargando publicación…
    </div>
    <div
      v-else-if="!listingId"
      class="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center"
    >
      <p class="text-sm text-muted-foreground">
        Este producto todavía no tiene una publicación vinculada.
      </p>
      <Link
        :href="route('matching.index')"
        class="mt-2 inline-block text-[12px] font-semibold text-brand hover:underline"
      >
        Ir a Matching →
      </Link>
    </div>
    <div
      v-else-if="error"
      class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800"
    >
      {{ error }}
      <button
        type="button"
        class="ml-2 font-semibold underline"
        @click="load"
      >
        Reintentar
      </button>
    </div>
    <template v-else-if="listing">
      <!-- 1. Galería por variante -->
      <div class="space-y-1.5">
        <h3 class="text-[12px] font-semibold tracking-tight text-slate-900">
          Fotos
        </h3>
        <ProductPictureGallery
          :groups="pictureGallery.groups"
          :needs-resync="pictureGallery.needs_resync"
          :resyncing="resyncing"
          @resync="resyncListing"
        />
      </div>

      <!-- 2. Barra comercial -->
      <div class="rounded-xl border border-slate-200/70 bg-white px-3 py-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
          <div class="min-w-0 space-y-1">
            <div
              v-if="priceSummary"
              class="flex flex-wrap items-baseline gap-1.5"
            >
              <template v-if="priceSummary.isRange">
                <MoneyText
                  :amount="priceSummary.min"
                  :currency="priceSummary.currency"
                  class="text-[20px] font-semibold tracking-tight text-slate-900"
                />
                <span class="text-[14px] text-muted-foreground">–</span>
                <MoneyText
                  :amount="priceSummary.max"
                  :currency="priceSummary.currency"
                  class="text-[20px] font-semibold tracking-tight text-slate-900"
                />
              </template>
              <MoneyText
                v-else
                :amount="priceSummary.min"
                :currency="priceSummary.currency"
                class="text-[22px] font-semibold tracking-tight text-slate-900"
              />
            </div>
            <p
              v-else
              class="text-[13px] text-muted-foreground"
            >
              Sin precio en variantes
            </p>
            <div class="flex flex-wrap items-center gap-1.5">
              <Badge
                variant="secondary"
                class="text-[10px] capitalize"
              >
                {{ listing.status }}
              </Badge>
              <span
                v-if="totalStock != null"
                class="text-[11px] tabular-nums text-slate-700"
              >
                Stock {{ totalStock }}
              </span>
              <span class="text-[11px] tabular-nums text-muted-foreground">
                {{ toCodeCase(listing.external_item_id) }}
              </span>
              <span
                v-if="listing.category_name"
                class="text-[11px] text-muted-foreground"
              >
                · {{ listing.category_name }}
              </span>
              <span
                v-if="listing.logistic_type"
                class="text-[11px] text-muted-foreground"
              >
                · {{ listing.logistic_type }}
              </span>
            </div>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <Button
              v-if="listing.status !== 'paused'"
              size="sm"
              variant="outline"
              class="h-7 px-2 text-[11px]"
              :disabled="statusBusy"
              @click="setStatus('paused')"
            >
              Pausar
            </Button>
            <Button
              v-else
              size="sm"
              variant="outline"
              class="h-7 px-2 text-[11px]"
              :disabled="statusBusy"
              @click="setStatus('active')"
            >
              Activar
            </Button>
            <a
              v-if="listing.permalink"
              :href="listing.permalink"
              target="_blank"
              rel="noopener noreferrer"
            >
              <Button
                size="sm"
                variant="outline"
                class="h-7 px-2 text-[11px]"
              >
                <ExternalLink class="mr-1 size-3.5" />
                Ver en ML
              </Button>
            </a>
          </div>
        </div>
      </div>

      <!-- 3. Variantes del canal -->
      <div class="overflow-hidden rounded-xl border border-slate-200/70">
        <div class="border-b border-slate-100 px-3 py-2">
          <h4 class="text-[12px] font-semibold tracking-tight">
            Variantes del canal
          </h4>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50/80 text-left text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            <tr>
              <th class="px-3 py-1.5">SKU / Variante</th>
              <th class="px-3 py-1.5">Precio</th>
              <th class="px-3 py-1.5">Stock</th>
              <th class="px-3 py-1.5">Match</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="v in variants"
              :key="v.id"
              class="border-t border-slate-100"
            >
              <td class="px-3 py-1.5">
                <p class="text-[12px] font-medium">
                  {{ v.sku_external || v.variant_sku || '—' }}
                </p>
                <p
                  v-if="v.attribute_combinations?.length"
                  class="text-[10px] text-muted-foreground"
                >
                  {{ attributeCombinationsLabel(v.attribute_combinations) }}
                </p>
              </td>
              <td class="px-3 py-1.5 tabular-nums">
                <MoneyText
                  v-if="v.price_amount != null"
                  :amount="v.price_amount"
                  :currency="v.currency_code || 'MXN'"
                />
                <span v-else>—</span>
              </td>
              <td class="px-3 py-1.5 tabular-nums">
                {{ v.available_quantity ?? '—' }}
              </td>
              <td class="px-3 py-1.5">
                <Badge
                  :variant="v.matched ? 'success' : 'muted'"
                  class="text-[10px]"
                >
                  {{ v.matched ? 'Matched' : 'Sin match' }}
                </Badge>
              </td>
            </tr>
            <tr v-if="!variants.length">
              <td
                colspan="4"
                class="px-3 py-6 text-center text-sm text-muted-foreground"
              >
                Sin variantes de canal.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 4. Características -->
      <div
        v-if="attributes.length"
        class="overflow-hidden rounded-xl border border-slate-200/70"
      >
        <div class="border-b border-slate-100 px-3 py-2">
          <h4 class="text-[12px] font-semibold tracking-tight">
            Características
          </h4>
        </div>
        <dl class="grid sm:grid-cols-2">
          <div
            v-for="(attr, idx) in attributes"
            :key="idx"
            class="flex gap-3 border-b border-slate-100 px-3 py-2 odd:bg-slate-50/50 sm:odd:bg-transparent sm:[&:nth-child(4n+1)]:bg-slate-50/50 sm:[&:nth-child(4n+2)]:bg-slate-50/50"
          >
            <dt class="w-[42%] shrink-0 text-[11px] text-muted-foreground">
              {{ attr.name }}
            </dt>
            <dd class="min-w-0 flex-1 text-[12px] text-slate-800">
              {{ attr.value }}
            </dd>
          </div>
        </dl>
      </div>

      <!-- 5. Descripción -->
      <div
        v-if="formattedDescription"
        class="rounded-xl border border-slate-200/70 px-3 py-2.5"
      >
        <p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
          Descripción
        </p>
        <p class="mt-1.5 whitespace-pre-wrap text-[12px] leading-relaxed text-slate-700">
          {{ formattedDescription }}
        </p>
      </div>
    </template>
  </div>
</template>
