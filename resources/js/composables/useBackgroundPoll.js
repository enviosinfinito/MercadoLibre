import { onMounted, onUnmounted, toValue, watch } from 'vue'

/**
 * Poll periódico con Visibility API + AbortController por tick.
 *
 * @param {Object} options
 * @param {(ctx: { getSignal: () => AbortSignal }) => void|Promise<void>} options.tick
 * @param {number} [options.intervalMs=30000]
 * @param {import('vue').MaybeRefOrGetter<boolean>} [options.enabled=true]
 * @param {boolean} [options.runOnMount=true]
 * @param {boolean} [options.runOnVisible=true]
 */
export function useBackgroundPoll({
  tick,
  intervalMs = 30000,
  enabled = true,
  runOnMount = true,
  runOnVisible = true,
} = {}) {
  let timerId = null
  let abortController = null
  let started = false
  let runningTick = false

  function isEnabled() {
    return Boolean(toValue(enabled))
  }

  function isDocumentVisible() {
    if (typeof document === 'undefined') return true
    return document.visibilityState !== 'hidden'
  }

  function clearTimer() {
    if (timerId != null) {
      clearTimeout(timerId)
      timerId = null
    }
  }

  function abortInFlight() {
    if (abortController) {
      abortController.abort()
      abortController = null
    }
  }

  function scheduleNext() {
    clearTimer()
    if (!started || !isEnabled() || !isDocumentVisible()) return
    timerId = setTimeout(() => {
      void runTick()
    }, intervalMs)
  }

  async function runTick() {
    if (!started || !isEnabled() || !isDocumentVisible() || runningTick) {
      scheduleNext()
      return
    }

    abortInFlight()
    abortController = new AbortController()
    const signal = abortController.signal
    runningTick = true

    try {
      await tick({ getSignal: () => signal })
    } catch (err) {
      if (err?.name !== 'AbortError') {
        console.warn('[useBackgroundPoll] tick failed:', err)
      }
    } finally {
      runningTick = false
      if (abortController?.signal === signal) {
        abortController = null
      }
      scheduleNext()
    }
  }

  function start() {
    if (started) return
    started = true
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', onVisibilityChange)
    }
    if (runOnMount && isEnabled() && isDocumentVisible()) {
      void runTick()
    } else {
      scheduleNext()
    }
  }

  function stop() {
    started = false
    clearTimer()
    abortInFlight()
    if (typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', onVisibilityChange)
    }
  }

  function runNow() {
    if (!started || !isEnabled()) return
    clearTimer()
    void runTick()
  }

  function onVisibilityChange() {
    if (!started) return
    if (isDocumentVisible()) {
      if (runOnVisible && isEnabled()) {
        void runTick()
      } else {
        scheduleNext()
      }
    } else {
      clearTimer()
      abortInFlight()
    }
  }

  watch(
    () => toValue(enabled),
    (on) => {
      if (!started) return
      if (on && isDocumentVisible()) {
        void runTick()
      } else if (!on) {
        clearTimer()
        abortInFlight()
      }
    },
  )

  onMounted(() => {
    start()
  })

  onUnmounted(() => {
    stop()
  })

  return {
    start,
    stop,
    runNow,
  }
}
