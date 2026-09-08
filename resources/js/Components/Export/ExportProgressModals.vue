<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import ExportProgressModalItem from '@/Components/Export/ExportProgressModalItem.vue'
import ExportPreviewSlideOver from '@/Components/Export/ExportPreviewSlideOver.vue'

const items = ref([])
const previewToken = ref(null)
const previewOpen = ref(false)

function upsert(detail) {
  const key = detail.stableKey || detail.token
  const idx = items.value.findIndex((i) => (i.stableKey || i.token) === key)
  if (idx >= 0) {
    items.value[idx] = { ...items.value[idx], ...detail }
  } else {
    items.value.push({ ...detail })
  }
}

function onStart(e) {
  upsert(e.detail || {})
}

function onResolve(e) {
  const { pendingToken, token, stableKey, ...rest } = e.detail || {}
  const idx = items.value.findIndex(
    (i) => i.token === pendingToken || i.stableKey === (stableKey || pendingToken),
  )
  if (idx >= 0) {
    items.value[idx] = {
      ...items.value[idx],
      ...rest,
      token,
      stableKey: stableKey || items.value[idx].stableKey || pendingToken,
    }
  } else {
    upsert({ token, stableKey: stableKey || pendingToken, ...rest })
  }
}

function onFail(e) {
  const { pendingToken, stableKey } = e.detail || {}
  items.value = items.value.filter(
    (i) => i.token !== pendingToken && i.stableKey !== (stableKey || pendingToken),
  )
}

function closeItem(item) {
  const key = item.stableKey || item.token
  items.value = items.value.filter((i) => (i.stableKey || i.token) !== key)
}

function openPreview(token) {
  previewToken.value = token
  previewOpen.value = true
}

onMounted(() => {
  window.addEventListener('export-start', onStart)
  window.addEventListener('export-pending-resolve', onResolve)
  window.addEventListener('export-pending-fail', onFail)
})

onUnmounted(() => {
  window.removeEventListener('export-start', onStart)
  window.removeEventListener('export-pending-resolve', onResolve)
  window.removeEventListener('export-pending-fail', onFail)
})
</script>

<template>
  <div class="pointer-events-none fixed bottom-4 right-4 z-[80] flex flex-col gap-3">
    <div
      v-for="item in items"
      :key="item.stableKey || item.token"
      class="pointer-events-auto"
    >
      <ExportProgressModalItem
        :item="item"
        @close="closeItem(item)"
        @preview="openPreview"
        @update="(u) => upsert({ ...item, ...u })"
      />
    </div>
  </div>

  <ExportPreviewSlideOver
    v-model:open="previewOpen"
    :token="previewToken"
  />
</template>
