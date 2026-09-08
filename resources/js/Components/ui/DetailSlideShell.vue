<template>
  <SlideOverShell
    :show="isShown"
    :title="title"
    :size="size"
    :loading="isLoading"
    :error="loadError"
    :close-only-header="closeOnlyHeader"
    :fill-height="fillHeight"
    :compact-header="compactHeader"
    :tabs-header="tabsHeader"
    :mobile-full-bleed="mobileFullBleed"
    :body-class="bodyClass"
    @close="detail.close()"
    @retry="detail.retry()"
  >
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <slot v-if="detailData && !isLoading && !loadError" :data="detailData" />

    <template v-if="$slots.footer" #footer>
      <slot name="footer" />
    </template>
  </SlideOverShell>
</template>

<script setup>
import { computed, unref } from 'vue'
import SlideOverShell from '@/Components/ui/SlideOverShell.vue'

const props = defineProps({
  detail: { type: Object, required: true },
  title: { type: String, default: 'Detalle' },
  size: { type: String, default: 'lg' },
  closeOnlyHeader: { type: Boolean, default: true },
  fillHeight: { type: Boolean, default: true },
  compactHeader: { type: Boolean, default: false },
  tabsHeader: { type: Boolean, default: false },
  mobileFullBleed: { type: Boolean, default: false },
  bodyClass: { type: String, default: 'bg-white' },
})

const isShown = computed(() => Boolean(unref(props.detail.show)))
const isLoading = computed(() => Boolean(unref(props.detail.loading)))
const loadError = computed(() => unref(props.detail.error) ?? null)
const detailData = computed(() => unref(props.detail.data))
</script>
