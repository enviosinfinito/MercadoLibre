import { ref } from 'vue'
import axios from 'axios'

export function useExportPreview() {
  const loading = ref(false)
  const error = ref(null)
  const headers = ref([])
  const rows = ref([])
  const totalRows = ref(0)
  const offset = ref(0)
  const hasMore = ref(false)

  async function fetchPreview(token, { mode = 'page', offset: off = 0, limit = 200 } = {}) {
    loading.value = true
    error.value = null
    try {
      const { data } = await axios.get(route('exports.preview'), {
        params: { token, mode, offset: off, limit },
      })
      headers.value = data.headers || []
      rows.value = data.rows || []
      totalRows.value = data.total_rows || 0
      offset.value = data.offset || 0
      hasMore.value = Boolean(data.has_more)
      return data
    } catch (e) {
      error.value = e
      throw e
    } finally {
      loading.value = false
    }
  }

  return {
    loading,
    error,
    headers,
    rows,
    totalRows,
    offset,
    hasMore,
    fetchPreview,
  }
}
