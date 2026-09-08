import { computed, onMounted, onUnmounted, ref, unref, watch } from 'vue'

/**
 * Detecta cuando un elemento sale del viewport y expone un flag para mostrar un espejo fixed.
 *
 * @param {Object} options
 * @param {import('vue').Ref<HTMLElement|null>} options.targetRef - Elemento observado
 * @param {import('vue').Ref<boolean>|boolean} [options.active=true] - Condición para mostrar espejo
 * @param {import('vue').Ref<boolean>|boolean} [options.enabled=true] - Si false, no observa y nunca muestra espejo
 * @param {string} [options.rootMargin='0px'] - Margen del IntersectionObserver
 * @param {number|number[]} [options.threshold=0] - Umbral del IntersectionObserver
 * @param {import('vue').Ref<HTMLElement|null>|HTMLElement|null} [options.root=null] - Scroll root opcional
 */
export function useStickyWhenOutOfView({
  targetRef,
  active = true,
  enabled = true,
  rootMargin = '0px',
  threshold = 0,
  root = null,
} = {}) {
  const isInView = ref(true)
  let observer = null

  const showMirror = computed(() => {
    const isEnabled = unref(enabled)
    const isActive = unref(active)
    return Boolean(isEnabled) && Boolean(isActive) && !isInView.value
  })

  function disconnect() {
    if (observer) {
      observer.disconnect()
      observer = null
    }
  }

  function setupObserver() {
    disconnect()

    if (!unref(enabled)) {
      isInView.value = true
      return
    }

    const target = unref(targetRef)
    if (!target || typeof IntersectionObserver === 'undefined') {
      isInView.value = true
      return
    }

    const observerOptions = { rootMargin, threshold }
    const scrollRoot = unref(root)
    if (scrollRoot) {
      observerOptions.root = scrollRoot
    }

    observer = new IntersectionObserver((entries) => {
      const entry = entries[0]
      if (entry) {
        isInView.value = entry.isIntersecting
      }
    }, observerOptions)

    observer.observe(target)
  }

  watch(
    () => [unref(targetRef), unref(enabled), unref(root)],
    () => {
      setupObserver()
    },
    { flush: 'post' }
  )

  onMounted(() => {
    setupObserver()
  })

  onUnmounted(() => {
    disconnect()
  })

  return { isInView, showMirror }
}
