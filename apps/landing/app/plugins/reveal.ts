import type { Directive } from 'vue'

/**
 * v-reveal: fades and lifts an element in when it scrolls into view.
 * `v-reveal="120"` delays it by 120 ms (for staggering siblings).
 * The attribute is rendered on the server so the first paint already has the start state;
 * without JavaScript the CSS never hides anything (it needs the .js class on <html>).
 */
const observed = new WeakMap<Element, IntersectionObserver>()

const reveal: Directive<HTMLElement, number | undefined> = {
  getSSRProps(binding) {
    return { 'data-reveal': '', style: binding.value ? `--reveal-delay:${binding.value}ms` : undefined }
  },
  mounted(el, binding) {
    el.setAttribute('data-reveal', '')
    if (binding.value) el.style.setProperty('--reveal-delay', `${binding.value}ms`)
    const io = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-in')
            io.unobserve(entry.target)
          }
        }
      },
      { rootMargin: '0px 0px -10% 0px', threshold: 0.12 },
    )
    io.observe(el)
    observed.set(el, io)
  },
  unmounted(el) {
    observed.get(el)?.disconnect()
    observed.delete(el)
  },
}

export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.directive('reveal', reveal)
})
