<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import Button from '@/Components/ui/Button.vue';
import ConnectionChip from '@/Components/Domain/ConnectionChip.vue';
import SlideOverShell from '@/Components/ui/SlideOverShell.vue';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import {
    ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS,
    SLIDE_OVER_TABS_LIST_CLASS,
    SLIDE_OVER_TABS_TRIGGER_CLASS,
} from '@/lib/slideOverLayout';
import { ClipboardList, Megaphone, MessageCircle, Package, Receipt, RefreshCw, ShieldAlert } from 'lucide-vue-next';

const OrderDetailPanel = defineAsyncComponent(
    () => import('@/Components/Orders/OrderDetailPanel.vue'),
);
const ProductDetailSlideOver = defineAsyncComponent(
    () => import('@/Components/Products/ProductDetailSlideOver.vue'),
);

interface ConnectionHint {
    id?: number | null;
    provider?: string | null;
    display_name?: string | null;
    external_user_id?: string | null;
    color?: string | null;
}

interface DetailMeta {
    hasShipment?: boolean;
    shipmentId?: number | null;
    status?: string | null;
    externalOrderId?: string | number | null;
    canSync?: boolean;
    channelLabel?: string | null;
    connectionId?: number | null;
    connectionLabel?: string | null;
    connectionColor?: string | null;
    buyerInboundCount?: number;
    hasClaims?: boolean;
    claimsOpenedCount?: number;
    claimsTotalCount?: number;
}

const props = withDefaults(
    defineProps<{
        show: boolean;
        orderId: number | null;
        /** Tab inicial / controlado externamente */
        initialTab?: string;
        /** Fallback del número externo mientras carga el panel */
        externalOrderId?: string | number | null;
        /** Conexión conocida desde la fila (chip mientras llega meta) */
        connection?: ConnectionHint | null;
    }>(),
    {
        initialTab: 'details',
        externalOrderId: null,
        connection: null,
    },
);

const emit = defineEmits<{
    close: [];
}>();

const detailTab = ref(props.initialTab);
const detailMeta = ref<DetailMeta | null>(null);
const orderDetailPanelRef = ref<{ syncNow?: () => Promise<void> } | null>(null);
const headerSyncing = ref(false);

const productSlideOpen = ref(false);
const selectedProductId = ref<number | null>(null);
const selectedMlItemId = ref<string | null>(null);
const productSlideInitialTab = ref<'publication' | 'product'>('publication');

watch(
    () => [props.show, props.orderId, props.initialTab] as const,
    ([show, orderId, tab]) => {
        if (show && orderId) {
            detailTab.value = tab || 'details';
            detailMeta.value = null;
            headerSyncing.value = false;
            return;
        }
        if (!show) {
            closeProductSlide();
        }
    },
);

const detailOrderNumber = computed(() => {
    const fromMeta = detailMeta.value?.externalOrderId;
    if (fromMeta != null && fromMeta !== '') return fromMeta;
    return props.externalOrderId ?? props.orderId;
});

const detailConnectionForChip = computed(() => {
    const base = props.connection;
    const label =
        detailMeta.value?.connectionLabel
        ?? detailMeta.value?.channelLabel
        ?? base?.display_name
        ?? null;
    const id = detailMeta.value?.connectionId ?? base?.id ?? null;
    if (id == null && !label) return null;
    return {
        id: id ?? 0,
        provider: base?.provider ?? 'mercadolibre',
        display_name: label,
        external_user_id: base?.external_user_id ?? null,
        color: detailMeta.value?.connectionColor ?? base?.color ?? null,
    };
});

const canSyncSelectedOrder = computed(() => {
    if (detailMeta.value?.canSync != null) return detailMeta.value.canSync;
    return Boolean(props.orderId);
});

async function syncSelectedOrder() {
    const panel = orderDetailPanelRef.value;
    if (!panel?.syncNow || headerSyncing.value) return;
    headerSyncing.value = true;
    try {
        await panel.syncNow();
    } finally {
        headerSyncing.value = false;
    }
}

function onClose() {
    closeProductSlide();
    emit('close');
}

function openProductFromLine(payload: { productId?: number | null; mlItemId?: string | null }) {
    const productId = payload.productId ?? null;
    const mlItemId = payload.mlItemId ?? null;
    if (productId == null && !mlItemId) return;
    selectedProductId.value = productId;
    selectedMlItemId.value = mlItemId;
    productSlideInitialTab.value = mlItemId ? 'publication' : 'product';
    productSlideOpen.value = true;
}

function closeProductSlide() {
    productSlideOpen.value = false;
    selectedProductId.value = null;
    selectedMlItemId.value = null;
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
        :max-width="ORDER_DETAIL_SLIDE_OVER_WIDTH_CLASS"
        accessibility-title="Detalle de orden"
        @close="onClose"
    >
        <template #header>
            <div class="min-w-0 space-y-1.5">
                <div class="flex h-7 min-w-0 items-center sm:h-8">
                    <h2
                        class="min-w-0 truncate text-[13px] font-semibold tracking-tight text-slate-900 tabular-nums sm:text-[14px]"
                        :title="`Orden #${detailOrderNumber}`"
                    >
                        #{{ detailOrderNumber }}
                    </h2>
                </div>
                <Tabs
                    v-model="detailTab"
                    class="min-w-0"
                >
                    <TabsList :class="SLIDE_OVER_TABS_LIST_CLASS">
                        <TabsTrigger
                            value="details"
                            :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                            title="Detalles"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <ClipboardList
                                    class="size-3.5 shrink-0 opacity-70"
                                    aria-hidden="true"
                                />
                                Detalles
                            </span>
                        </TabsTrigger>
                        <TabsTrigger
                            v-if="detailMeta?.hasShipment"
                            value="shipment"
                            :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                            title="Envío"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <Package
                                    class="size-3.5 shrink-0 opacity-70"
                                    aria-hidden="true"
                                />
                                Envío
                            </span>
                        </TabsTrigger>
                        <TabsTrigger
                            value="messages"
                            :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                            title="Mensajes"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <MessageCircle
                                    class="size-3.5 shrink-0 opacity-70"
                                    aria-hidden="true"
                                />
                                Mensajes
                                <span
                                    v-if="(detailMeta?.buyerInboundCount ?? 0) > 0"
                                    class="inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-brand px-1 text-[9px] font-semibold leading-none text-white"
                                >
                                    {{ detailMeta?.buyerInboundCount }}
                                </span>
                            </span>
                        </TabsTrigger>
                        <TabsTrigger
                            v-if="detailMeta?.hasAds"
                            value="ads"
                            :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                            title="Publicidad"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <Megaphone
                                    class="size-3.5 shrink-0 opacity-70"
                                    aria-hidden="true"
                                />
                                Publicidad
                            </span>
                        </TabsTrigger>
                        <TabsTrigger
                            value="invoice"
                            :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                            title="Factura"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <Receipt
                                    class="size-3.5 shrink-0 opacity-70"
                                    aria-hidden="true"
                                />
                                Factura
                            </span>
                        </TabsTrigger>
                        <TabsTrigger
                            value="claims"
                            :class="SLIDE_OVER_TABS_TRIGGER_CLASS"
                            title="Reclamos"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <ShieldAlert
                                    class="size-3.5 shrink-0 opacity-70"
                                    aria-hidden="true"
                                />
                                Reclamos
                                <span
                                    v-if="(detailMeta?.claimsOpenedCount ?? 0) > 0"
                                    class="inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-brand px-1 text-[9px] font-semibold leading-none text-white"
                                >
                                    {{ detailMeta?.claimsOpenedCount }}
                                </span>
                            </span>
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>
        </template>

        <template #header-actions>
            <ConnectionChip
                v-if="detailConnectionForChip"
                class="mr-0.5 max-w-[7.5rem] sm:max-w-[14rem]"
                :connection="detailConnectionForChip"
                :account-only="false"
                :compact="false"
            />
            <Button
                v-if="canSyncSelectedOrder"
                type="button"
                size="icon"
                variant="outline"
                class="size-7 shrink-0 rounded-full sm:size-8"
                :disabled="headerSyncing || !orderId"
                :title="headerSyncing ? 'Actualizando…' : 'Resincronizar con Mercado Libre'"
                :aria-label="headerSyncing ? 'Actualizando…' : 'Resincronizar con Mercado Libre'"
                @click="syncSelectedOrder"
            >
                <RefreshCw
                    class="size-3.5"
                    :class="{ 'animate-spin': headerSyncing }"
                />
            </Button>
        </template>

        <OrderDetailPanel
            v-if="orderId"
            ref="orderDetailPanelRef"
            :order-id="orderId"
            :selected-tab="detailTab"
            @update:selected-tab="detailTab = $event"
            @meta="detailMeta = $event"
            @open-product="openProductFromLine"
        />
    </SlideOverShell>

    <ProductDetailSlideOver
        :show="productSlideOpen"
        :product-id="selectedProductId"
        :ml-item-id="selectedMlItemId"
        :initial-tab="productSlideInitialTab"
        @close="closeProductSlide"
    />
</template>
