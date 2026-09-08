import { computed } from 'vue'

/**
 * Helpers for resolving ActionBar preset/position from page context.
 */
export function useActionBar(context = {}) {
  const preset = computed(() => {
    if (context.inSlide) return 'slide'
    if (context.inModal) return 'modal'
    if (context.isWizard) return 'wizard'
    return 'form'
  })

  const position = computed(() => {
    if (context.inSlide) return 'sticky-bottom'
    if (context.inModal) return 'modal'
    if (context.isWizard) return 'dock'
    return 'flow'
  })

  function buildActions(config) {
    return { ...config }
  }

  return { preset, position, buildActions }
}
