import { inject, provide } from 'vue'

export const ACTION_BAR_CONTEXT = Symbol('actionBarContext')

export function provideActionBarContext(context) {
  provide(ACTION_BAR_CONTEXT, context)
}

export function useActionBarContext(fallback = {}) {
  return inject(ACTION_BAR_CONTEXT, fallback)
}
