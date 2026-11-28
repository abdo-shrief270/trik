import { injectHead } from '@unhead/vue'

/**
 * Workaround: in this client-only app (ssr: false, Nuxt 4.7) head updates made after the first render stay
 * queued ("dirty") and never reach the DOM, so <html dir/lang> and page titles were missing: Arabic pages had
 * no RTL until the language was switched. Rendering explicitly after each page and language change fixes it.
 * Remove when the upstream issue is fixed (check: load /sign-in in Arabic, <html dir="rtl"> must be set).
 */
export default defineNuxtPlugin({
  name: 'trik:head-sync',
  enforce: 'post',
  setup(nuxtApp) {
    const head = nuxtApp.vueApp.runWithContext(() => injectHead())
    const render = async (): Promise<void> => {
      await nextTick()
      head.render()
    }

    nuxtApp.hook('page:finish', render)
    nuxtApp.hook('app:mounted', render)
    nuxtApp.hook('i18n:localeSwitched', render)
    // Titles set from reactive sources (e.g. the signed-in name) change without a navigation.
    head.hooks?.hook('entries:updated', () => {
      if (head.dirty) void render()
    })
  },
})
