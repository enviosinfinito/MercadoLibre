import { router } from '@inertiajs/vue3'

/**
 * @param {object} options
 * @param {string} options.routeUrl
 * @param {object} [options.routerOptions]
 * @param {(params: object) => object} [options.transformParams]
 */
export function createInertiaAdapter(options = {}) {
  const { routeUrl, routerOptions = {}, transformParams = (p) => p } = options

  return {
    async apply(params) {
      const payload = transformParams(params)
      return new Promise((resolve, reject) => {
        router.get(routeUrl, payload, {
          preserveState: true,
          preserveScroll: true,
          replace: true,
          ...routerOptions,
          onFinish: () => resolve(null),
          onError: (errors) => reject(errors),
        })
      })
    },
  }
}
