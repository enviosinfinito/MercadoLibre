<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue'
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs'
import { ActionBar, ActionButton, ActionGroup } from '@/Components/ui/ActionBar'
import ProductPublicationPanel from '@/Components/Products/ProductPublicationPanel.vue'
import ProductSystemPanel from '@/Components/Products/ProductSystemPanel.vue'
import ProductSalesPanel from '@/Components/Products/ProductSalesPanel.vue'
import ProductAdsPanel from '@/Components/Products/ProductAdsPanel.vue'
import ProductQuestionsPanel from '@/Components/Products/ProductQuestionsPanel.vue'
import ProductReturnsPanel from '@/Components/Products/ProductReturnsPanel.vue'
import { fetchSlidePayload } from '@/lib/fetchSlidePayload'
import { toTitleCase } from '@/lib/formatDisplayText'
import {
  ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS,
  SLIDE_OVER_TABS_LIST_CLASS,
  SLIDE_OVER_TABS_TRIGGER_CLASS,
} from '@/lib/slideOverLayout'
import { BadgePercent, Megaphone, MessageCircleQuestionMark, Package, RotateCcw, TrendingUp } from 'lucide-vue-next'

export type ProductSlideTab = 'publication' | 'product' | 'sales' | 'ads' | 'questions' | 'returns'

const props = withDefaults(
  defineProps<{
    show: boolean
    /** Tab inicial según el contexto que abre el slide */
    initialTab?: ProductSlideTab
    productId?: number | null
    listingId?: number | null
    mlItemId?: string | null
    /** Clave returns (`123` o `ml:MLM…`) si ya se conoce */
    returnsKey?: string | null
    period?: string
    connectionIds?: number[]
  }>(),
  {
    initialTab: 'publication',
    productId: null,
    listingId: null,
    mlItemId: null,
    returnsKey: null,
    period: 'last_30_days',
    connectionIds: () => [],
  },
)

const emit = defineEmits<{
  close: []
  saved: [payload: unknown]
}>()

const loading = ref(false)
const error = ref<string | null>(null)
const context = ref<Record<string, any> | null>(null)
const activeTab = ref<ProductSlideTab>(props.initialTab)

const productPanelRef = ref<{ save?: () => Promise<void> } | null>(null)
const productMeta = ref({ unsavedChanges: false, saving: false, canSave: false })

const title = computed(() => {
  const raw = context.value?.title
  if (!raw) return 'Producto'
  return toTitleCase(raw)
})
const headerImage = computed(() => context.value?.image || null)
const connection = computed(() => context.value?.connection || null)

const resolvedProductId = computed(() => context.value?.product_id ?? props.productId ?? null)
const resolvedListingId = computed(() => context.value?.listing_id ?? props.listingId ?? null)
const resolvedMlItemId = computed(() => context.value?.ml_item_id ?? props.mlItemId ?? null)
const resolvedReturnsKey = computed(
  () => context.value?.returns_key ?? props.returnsKey ?? null,
)

const tabsAvailable = computed(() => ({
  publication: Boolean(context.value?.tabs?.publication ?? resolvedListingId.value ?? props.mlItemId),
  product: Boolean(context.value?.tabs?.product ?? resolvedProductId.value),
  sales: Boolean(context.value?.tabs?.sales ?? resolvedProductId.value),
  ads: Boolean(context.value?.tabs?.ads ?? resolvedProductId.value ?? resolvedMlItemId.value),
  questions: Boolean(
    context.value?.tabs?.questions ?? resolvedProductId.value ?? resolvedMlItemId.value,
  ),
  returns: Boolean(context.value?.tabs?.returns ?? resolvedReturnsKey.value),
}))

function buildContextUrl() {
  const params = new URLSearchParams()
  if (props.productId) params.set('product_id', String(props.productId))
  if (props.listingId) params.set('listing_id', String(props.listingId))
  if (props.mlItemId) params.set('ml_item_id', String(props.mlItemId))
  if (!props.productId && !props.listingId && !props.mlItemId && props.returnsKey) {
    if (String(props.returnsKey).startsWith('ml:')) {
      params.set('ml_item_id', String(props.returnsKey).slice(3))
    } else {
      params.set('product_id', String(props.returnsKey))
    }
  }
  const base = route('catalog.product-slide-context')
  const qs = params.toString()
  return qs ? `${base}?${qs}` : base
}

async function loadContext() {
  loading.value = true
  error.value = null
  context.value = null
  try {
    const data = await fetchSlidePayload(buildContextUrl(), { cache: 'no-store' })
    context.value = data
    const requested = props.initialTab || 'publication'
    if (data.tabs?.[requested]) {
      activeTab.value = requested
    } else if (data.tabs?.publication) {
      activeTab.value = 'publication'
    } else if (data.tabs?.product) {
      activeTab.value = 'product'
    } else if (data.tabs?.sales) {
      activeTab.value = 'sales'
    } else if (data.tabs?.ads) {
      activeTab.value = 'ads'
    } else if (data.tabs?.questions) {
      activeTab.value = 'questions'
    } else {
      activeTab.value = 'returns'
    }
  } catch (e: any) {
    error.value = typeof e?.message === 'string' ? e.message : 'No se pudo abrir el producto.'
  } finally {
    loading.value = false
  }
}

watch(
  () =>
    [
      props.show,
      props.productId,
      props.listingId,
      props.mlItemId,
      props.returnsKey,
      props.initialTab,
    ] as const,
  ([show]) => {
    if (show) {
      activeTab.value = props.initialTab || 'publication'
      void loadContext()
    } else {
      context.value = null
      error.value = null
      productMeta.value = { unsavedChanges: false, saving: false, canSave: false }
    }
  },
)

function onProductMeta(meta: { unsavedChanges: boolean; saving: boolean; canSave: boolean }) {
  productMeta.value = meta
}

function onProductSaved(payload: unknown) {
  emit('saved', payload)
}

async function saveProduct() {
  await productPanelRef.value?.save?.()
}
</script>

<template>
  <SlideOverShell
    :show="show"
    close-only-header
    tabs-header
    compact-header
    fill-height
    mobile-full-bleed
    :loading="loading"
    :error="error"
    :unsaved-changes="activeTab === 'product' && productMeta.unsavedChanges"
    :max-width="ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS"
    accessibility-title="Detalle de producto"
    @close="emit('close')"
    @retry="loadContext"
  >
    <template #header>
      <div class="min-w-0 space-y-1.5">
        <div class="flex h-7 min-w-0 items-center gap-2 sm:h-8">
          <img
            v-if="headerImage"
            :src="headerImage"
            alt=""
            referrerpolicy="no-referrer"
            class="size-7 shrink-0 rounded-md object-cover ring-1 ring-slate-200/80"
          >
          <h2
            class="min-w-0 truncate text-[13px] font-semibold tracking-tight text-slate-900 sm:text-[14px]"
            :title="title"
          >
            {{ title }}
          </h2>
        </div>
        <Tabs
          v-model="activeTab"
          class="min-w-0"
        >
          <TabsList :class="SLIDE_OVER_TABS_LIST_CLASS">
            <TabsTrigger
              v-if="tabsAvailable.publication || !context"
              value="publication"
              :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
              title="Publicación"
            >
              <span class="inline-flex items-center gap-1.5">
                <Megaphone
                  class="size-3.5 shrink-0 opacity-70"
                  aria-hidden="true"
                />
                Publicación
              </span>
            </TabsTrigger>
            <TabsTrigger
              v-if="tabsAvailable.product || !context"
              value="product"
              :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
              title="Producto"
            >
              <span class="inline-flex items-center gap-1.5">
                <Package
                  class="size-3.5 shrink-0 opacity-70"
                  aria-hidden="true"
                />
                Producto
              </span>
            </TabsTrigger>
            <TabsTrigger
              v-if="tabsAvailable.sales || !context"
              value="sales"
              :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
              title="Ventas"
            >
              <span class="inline-flex items-center gap-1.5">
                <TrendingUp
                  class="size-3.5 shrink-0 opacity-70"
                  aria-hidden="true"
                />
                Ventas
              </span>
            </TabsTrigger>
            <TabsTrigger
              v-if="tabsAvailable.ads || !context"
              value="ads"
              :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
              title="Publicidad"
            >
              <span class="inline-flex items-center gap-1.5">
                <BadgePercent
                  class="size-3.5 shrink-0 opacity-70"
                  aria-hidden="true"
                />
                Publicidad
              </span>
            </TabsTrigger>
            <TabsTrigger
              v-if="tabsAvailable.questions || !context"
              value="questions"
              :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
              title="Preguntas"
            >
              <span class="inline-flex items-center gap-1.5">
                <MessageCircleQuestionMark
                  class="size-3.5 shrink-0 opacity-70"
                  aria-hidden="true"
                />
                Preguntas
              </span>
            </TabsTrigger>
            <TabsTrigger
              v-if="tabsAvailable.returns || !context"
              value="returns"
              :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
              title="Devoluciones"
            >
              <span class="inline-flex items-center gap-1.5">
                <RotateCcw
                  class="size-3.5 shrink-0 opacity-70"
                  aria-hidden="true"
                />
                Devoluciones
              </span>
            </TabsTrigger>
          </TabsList>
        </Tabs>
      </div>
    </template>

    <template #header-actions>
      <ConnectionChip
        v-if="connection"
        class="mr-0.5 max-w-[7.5rem] sm:max-w-[12rem]"
        :connection="connection"
      />
    </template>

    <div
      v-if="context && !loading && !error"
      class="min-h-0 flex-1 overflow-auto"
    >
      <ProductPublicationPanel
        v-show="activeTab === 'publication'"
        :listing-id="resolvedListingId"
      />
      <ProductSystemPanel
        v-show="activeTab === 'product'"
        ref="productPanelRef"
        :product-id="resolvedProductId"
        :listing-id="resolvedListingId"
        :active="activeTab === 'product'"
        @meta="onProductMeta"
        @saved="onProductSaved"
      />
      <ProductSalesPanel
        v-show="activeTab === 'sales'"
        :product-id="resolvedProductId"
        period="all"
        :connection-ids="connectionIds"
        :active="activeTab === 'sales'"
      />
      <ProductAdsPanel
        v-show="activeTab === 'ads'"
        :product-id="resolvedProductId"
        :period="period"
        :connection-ids="connectionIds"
        :active="activeTab === 'ads'"
      />
      <ProductQuestionsPanel
        v-show="activeTab === 'questions'"
        :product-id="resolvedProductId"
        :ml-item-id="resolvedMlItemId"
        :connection-ids="connectionIds"
        :active="activeTab === 'questions'"
      />
      <ProductReturnsPanel
        v-show="activeTab === 'returns'"
        :returns-key="resolvedReturnsKey"
        :period="period"
        :connection-ids="connectionIds"
        :active="activeTab === 'returns'"
      />
    </div>

    <template
      v-if="activeTab === 'product' && productMeta.canSave"
      #footer
    >
      <ActionBar
        preset="slide"
        position="sticky-bottom"
        :show-informative-pages="false"
      >
        <template #end>
          <ActionGroup>
            <ActionButton
              label="Cerrar"
              variant="secondary"
              @click="emit('close')"
            />
            <ActionButton
              label="Guardar"
              loading-label="Guardando…"
              variant="brand"
              :loading="productMeta.saving"
              @click="saveProduct"
            />
          </ActionGroup>
        </template>
      </ActionBar>
    </template>
  </SlideOverShell>
</template>
