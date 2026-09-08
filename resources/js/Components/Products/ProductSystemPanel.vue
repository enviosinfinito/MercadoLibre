<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import Badge from '@/Components/ui/Badge.vue'
import Button from '@/Components/ui/Button.vue'
import Input from '@/Components/ui/Input.vue'
import ProductPictureGallery from '@/Components/Products/ProductPictureGallery.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { toCodeCase, toTitleCase } from '@/lib/formatDisplayText'

const props = defineProps({
  productId: { type: [Number, String], default: null },
  listingId: { type: [Number, String], default: null },
  active: { type: Boolean, default: true },
})

const emit = defineEmits(['saved', 'meta'])

const loading = ref(false)
const error = ref(null)
const saving = ref(false)
const saveError = ref(null)
const fieldErrors = ref({})
const product = ref(null)
const matchedListings = ref([])
const pendingImages = ref([])
const pictureGallery = ref({ cover_url: null, groups: [], needs_resync: false })
const uploading = ref(false)
const uploadError = ref(null)
const fileInputRef = ref(null)
const resyncing = ref(false)

const form = reactive({
  name: '',
  description: '',
  status: 'active',
  variants: [{ sku: '', name: '', gtin: '' }],
})

const galleryGroups = computed(() => pictureGallery.value?.groups || [])
const galleryNeedsResync = computed(() => Boolean(pictureGallery.value?.needs_resync))
const photoCount = computed(() =>
  galleryGroups.value.reduce((sum, g) => sum + (g.pictures?.length || 0), 0),
)

const primaryListingId = computed(
  () => props.listingId ?? matchedListings.value?.[0]?.listing_id ?? null,
)

function applyPictureGallery(data) {
  pictureGallery.value = data?.picture_gallery ?? {
    cover_url: null,
    groups: [],
    needs_resync: false,
  }
}

function syncPendingIntoGallery() {
  const base = (pictureGallery.value?.groups || []).filter((g) => g.kind !== 'pending')
  const groups = [...base]
  if (pendingImages.value.length) {
    groups.push({
      key: 'pending',
      kind: 'pending',
      label: 'Pendientes de publicar',
      sku: null,
      variant_id: null,
      listing_id: null,
      pictures: pendingImages.value,
    })
  }
  pictureGallery.value = {
    ...pictureGallery.value,
    groups,
    cover_url: groups[0]?.pictures?.[0]?.url ?? pictureGallery.value?.cover_url ?? null,
  }
}

const unsavedChanges = computed(() => {
  if (!product.value) return false
  const baseline = serializeForm({
    name: product.value.name,
    description: product.value.description ?? '',
    status: product.value.status || 'active',
    variants: product.value.variants?.length
      ? product.value.variants.map((v) => ({
          id: v.id,
          sku: v.sku,
          name: v.name ?? '',
          gtin: v.gtin ?? '',
        }))
      : [{ sku: '', name: '', gtin: '' }],
  })
  return serializeForm(form) !== baseline
})

function serializeForm(value) {
  return JSON.stringify({
    name: value.name,
    description: value.description,
    status: value.status,
    variants: value.variants.map((v) => ({
      id: v.id ?? null,
      sku: v.sku,
      name: v.name,
      gtin: v.gtin,
    })),
  })
}

function hydrateForm(p) {
  form.name = p.name
  form.description = p.description ?? ''
  form.status = p.status || 'active'
  form.variants = p.variants?.length
    ? p.variants.map((v) => ({
        id: v.id,
        sku: v.sku,
        name: v.name ?? '',
        gtin: v.gtin ?? '',
      }))
    : [{ sku: '', name: '', gtin: '' }]
}

async function load(id) {
  if (!id) {
    product.value = null
    matchedListings.value = []
    pendingImages.value = []
    pictureGallery.value = { cover_url: null, groups: [], needs_resync: false }
    emit('meta', { unsavedChanges: false, saving: false, canSave: false })
    return
  }
  loading.value = true
  error.value = null
  saveError.value = null
  uploadError.value = null
  fieldErrors.value = {}
  product.value = null
  matchedListings.value = []
  pendingImages.value = []
  pictureGallery.value = { cover_url: null, groups: [], needs_resync: false }
  try {
    const data = await fetchSlidePayload(route('products.edit', id), {
      cache: 'no-store',
      forceRefresh: true,
    })
    product.value = data.product
    matchedListings.value = data.matchedListings ?? data.matched_listings ?? []
    pendingImages.value = data.pending_images ?? data.pendingImages ?? []
    applyPictureGallery(data)
    hydrateForm(data.product)
  } catch (e) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo cargar el producto.'
  } finally {
    loading.value = false
    emitMeta()
  }
}

function openFilePicker() {
  fileInputRef.value?.click?.()
}

async function onFilesSelected(event) {
  const files = Array.from(event?.target?.files || [])
  if (fileInputRef.value) fileInputRef.value.value = ''
  if (!files.length || !product.value?.id) return

  uploading.value = true
  uploadError.value = null
  try {
    for (const file of files) {
      const body = new FormData()
      body.append('image', file)
      if (primaryListingId.value) {
        body.append('channel_listing_id', String(primaryListingId.value))
      }
      const response = await fetch(route('products.images.store', product.value.id), {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': getXsrfToken(),
        },
        credentials: 'same-origin',
        body,
      })
      const data = await response.json().catch(() => ({}))
      if (!response.ok) {
        throw new Error(data.message || `Error ${response.status}`)
      }
      if (data.image) {
        pendingImages.value = [...pendingImages.value, data.image]
      }
    }
    syncPendingIntoGallery()
  } catch (e) {
    uploadError.value = typeof e?.message === 'string' ? e.message : 'No se pudo subir la imagen.'
  } finally {
    uploading.value = false
  }
}

async function removePendingImage(imageId) {
  if (!product.value?.id || !imageId) return
  uploadError.value = null
  try {
    const response = await fetch(
      route('products.images.destroy', {
        product: product.value.id,
        productImage: imageId,
      }),
      {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': getXsrfToken(),
        },
        credentials: 'same-origin',
      },
    )
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new Error(data.message || `Error ${response.status}`)
    }
    pendingImages.value = pendingImages.value.filter((img) => img.id !== imageId)
    syncPendingIntoGallery()
  } catch (e) {
    uploadError.value = typeof e?.message === 'string' ? e.message : 'No se pudo eliminar la imagen.'
  }
}

function addVariant() {
  form.variants.push({ sku: '', name: '', gtin: '' })
}

function removeVariant(index) {
  if (form.variants.length <= 1) return
  form.variants.splice(index, 1)
}

function variantError(index, field) {
  return fieldErrors.value[`variants.${index}.${field}`]
}

function getXsrfToken() {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

async function resyncCatalog() {
  const listingId = primaryListingId.value
  if (!listingId || resyncing.value) return
  resyncing.value = true
  uploadError.value = null
  try {
    const response = await fetch(route('publications.sync-now', listingId), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      credentials: 'same-origin',
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new Error(data.message || `Error ${response.status}`)
    }
    if (product.value?.id) {
      await load(product.value.id)
    }
  } catch (e) {
    uploadError.value =
      typeof e?.message === 'string' ? e.message : 'No se pudo actualizar la publicación.'
  } finally {
    resyncing.value = false
  }
}

async function save() {
  if (!product.value?.id) return
  saving.value = true
  saveError.value = null
  fieldErrors.value = {}
  emitMeta()
  try {
    const response = await fetch(route('products.update', product.value.id), {
      method: 'PUT',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        name: form.name,
        description: form.description,
        status: form.status,
        variants: form.variants,
      }),
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      if (data.errors && typeof data.errors === 'object') {
        const flat = {}
        for (const [key, messages] of Object.entries(data.errors)) {
          flat[key] = Array.isArray(messages) ? messages[0] : String(messages)
        }
        fieldErrors.value = flat
      }
      throw new Error(data.message || `Error ${response.status}`)
    }
    if (data.product) {
      product.value = data.product
      hydrateForm(data.product)
    }
    emit('saved', data)
    router.reload({ only: ['products'], preserveState: true, preserveScroll: true })
  } catch (e) {
    saveError.value = typeof e?.message === 'string' ? e.message : 'No se pudo guardar.'
  } finally {
    saving.value = false
    emitMeta()
  }
}

function emitMeta() {
  emit('meta', {
    unsavedChanges: unsavedChanges.value,
    saving: saving.value,
    canSave: Boolean(product.value?.id),
  })
}

watch(unsavedChanges, () => emitMeta())

watch(
  () => [props.productId, props.listingId, props.active],
  ([id, , active]) => {
    if (active && id) {
      void load(id)
      return
    }
    if (!id) {
      product.value = null
      error.value = null
      listingPictures.value = []
      pendingImages.value = []
    }
  },
  { immediate: true },
)

defineExpose({ save, unsavedChanges, saving, reload: () => load(props.productId) })
</script>

<template>
  <div class="space-y-4 p-3 sm:p-4">
    <div
      v-if="loading"
      class="py-16 text-center text-sm text-muted-foreground"
    >
      Cargando producto…
    </div>
    <div
      v-else-if="!productId"
      class="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center"
    >
      <p class="text-sm text-muted-foreground">
        No hay producto canónico matchado a esta publicación.
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
    </div>
    <template v-else-if="product">
      <p
        v-if="saveError"
        class="rounded-xl border border-red-200/80 bg-red-50 px-2.5 py-1.5 text-xs text-red-800"
      >
        {{ saveError }}
      </p>

      <div class="space-y-3">
        <div>
          <h3 class="text-[12px] font-semibold tracking-tight text-slate-900">
            Identidad canónica
          </h3>
          <p class="text-[10px] text-muted-foreground">
            Datos del producto en el sistema. El título y la descripción de ML están en Publicación.
          </p>
        </div>

        <div>
          <label class="mb-1 block text-[11px] font-medium text-slate-700">Nombre interno</label>
          <Input
            v-model="form.name"
            required
            maxlength="255"
            class="h-8 text-xs shadow-none"
          />
          <p
            v-if="fieldErrors.name"
            class="mt-1 text-[10px] text-destructive"
          >
            {{ fieldErrors.name }}
          </p>
        </div>

        <div>
          <label class="mb-1 block text-[11px] font-medium text-slate-700">
            Descripción interna
          </label>
          <textarea
            v-model="form.description"
            rows="5"
            class="flex min-h-[6.5rem] w-full resize-y rounded-[14px] border border-slate-200/80 bg-white px-2.5 py-2 text-[12px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>

        <div>
          <label class="mb-1 block text-[11px] font-medium text-slate-700">Estado interno</label>
          <select
            v-model="form.status"
            class="flex h-8 w-full rounded-md border border-input bg-white px-2.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <option value="active">Activo</option>
            <option value="inactive">Inactivo</option>
          </select>
          <p class="mt-1 text-[10px] text-muted-foreground">
            Activo/Inactivo del producto en el sistema (no es el status de ML).
          </p>
        </div>
      </div>

      <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
          <div>
            <h3 class="text-[12px] font-semibold tracking-tight text-slate-900">
              Fotos
              <span
                v-if="photoCount"
                class="ml-1 font-normal text-muted-foreground"
              >
                ({{ photoCount }})
              </span>
            </h3>
            <p class="text-[10px] text-muted-foreground">
              Separadas por variante cuando ML las tiene asignadas. Las nuevas quedan como pendientes.
            </p>
          </div>
          <Button
            size="sm"
            variant="outline"
            type="button"
            class="h-7 px-2 text-[11px]"
            :disabled="uploading"
            @click="openFilePicker"
          >
            {{ uploading ? 'Subiendo…' : 'Adjuntar' }}
          </Button>
          <input
            ref="fileInputRef"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            class="hidden"
            @change="onFilesSelected"
          >
        </div>

        <p
          v-if="uploadError"
          class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-[11px] text-red-800"
        >
          {{ uploadError }}
        </p>

        <ProductPictureGallery
          :groups="galleryGroups"
          :needs-resync="galleryNeedsResync"
          :resyncing="resyncing"
          allow-remove-pending
          @remove-pending="removePendingImage"
          @resync="resyncCatalog"
        >
          <template #empty-action>
            <Button
              size="sm"
              variant="outline"
              type="button"
              class="mt-2 h-7 px-2 text-[11px]"
              :disabled="uploading"
              @click="openFilePicker"
            >
              Adjuntar foto
            </Button>
          </template>
        </ProductPictureGallery>
      </div>

      <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
          <div>
            <h3 class="text-[12px] font-semibold tracking-tight text-slate-900">
              Variantes canónicas
            </h3>
            <p class="text-[10px] text-muted-foreground">
              SKU / GTIN del sistema. Precio y stock del canal están en Publicación.
            </p>
          </div>
          <Button
            size="sm"
            variant="outline"
            type="button"
            class="h-7 px-2 text-[11px]"
            @click="addVariant"
          >
            Agregar
          </Button>
        </div>

        <div
          v-for="(variant, index) in form.variants"
          :key="variant.id ?? `new-${index}`"
          class="space-y-2 rounded-xl border border-slate-200/70 p-2.5"
        >
          <div class="flex items-center justify-between">
            <span class="text-[10px] font-medium text-muted-foreground">
              Variante {{ index + 1 }}
            </span>
            <Button
              v-if="form.variants.length > 1"
              size="sm"
              variant="ghost"
              type="button"
              class="h-7 px-2 text-[11px]"
              @click="removeVariant(index)"
            >
              Quitar
            </Button>
          </div>
          <div class="grid gap-2 sm:grid-cols-3">
            <div>
              <label class="mb-1 block text-[11px] font-medium text-slate-700">SKU</label>
              <Input
                v-model="variant.sku"
                required
                maxlength="255"
                class="h-8 font-mono text-xs uppercase shadow-none"
              />
              <p
                v-if="variantError(index, 'sku')"
                class="mt-1 text-[10px] text-destructive"
              >
                {{ variantError(index, 'sku') }}
              </p>
            </div>
            <div>
              <label class="mb-1 block text-[11px] font-medium text-slate-700">Nombre</label>
              <Input
                v-model="variant.name"
                maxlength="255"
                class="h-8 text-xs shadow-none"
              />
            </div>
            <div>
              <label class="mb-1 block text-[11px] font-medium text-slate-700">GTIN</label>
              <Input
                v-model="variant.gtin"
                maxlength="64"
                class="h-8 font-mono text-xs uppercase shadow-none"
              />
            </div>
          </div>
        </div>
      </div>

      <div>
        <h3 class="mb-0.5 text-[12px] font-semibold tracking-tight text-slate-900">
          Listings vinculados
        </h3>
        <p class="mb-1.5 text-[10px] text-muted-foreground">
          Publicaciones matchadas a los SKUs de este producto.
        </p>
        <p
          v-if="matchedListings.length === 0"
          class="text-[12px] text-muted-foreground"
        >
          Sin listings. Puedes matcharlos en
          <Link
            :href="route('matching.index')"
            class="font-medium text-brand hover:underline"
          >
            Matching
          </Link>.
        </p>
        <ul
          v-else
          class="divide-y divide-slate-100 rounded-xl border border-slate-200/70"
        >
          <li
            v-for="row in matchedListings"
            :key="row.id"
            class="flex flex-col gap-1 px-2.5 py-2 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0">
              <div class="truncate text-[12px] font-medium tracking-tight">
                {{ row.title ? toTitleCase(row.title) : 'Listing sin título' }}
              </div>
              <div class="text-[10px] text-muted-foreground">
                <span v-if="row.external_item_id">{{ toCodeCase(row.external_item_id) }}</span>
                <span v-if="row.provider"> · {{ row.provider }}</span>
                <span v-if="row.variant_sku"> · SKU {{ toCodeCase(row.variant_sku) }}</span>
              </div>
            </div>
            <Badge
              v-if="row.status"
              variant="secondary"
              class="h-5 rounded-full px-1.5 py-0 text-[10px] font-medium capitalize"
            >
              {{ row.status }}
            </Badge>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>
