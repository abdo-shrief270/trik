import Lenis from 'lenis'

/**
 * Smooth scrolling (Lenis) on its own animation loop. Off entirely when the visitor asks for reduced motion.
 * GSAP is not loaded here: only the sections that need it import it (see RoadSection), so other pages stay light.
 */
export default defineNuxtPlugin((nuxtApp) => {
  document.documentElement.classList.add('js')

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  const lenis = reduced ? null : new Lenis({ duration: 1.1, smoothWheel: true, autoRaf: true })

  const router = useRouter()
  router.afterEach((to, from) => {
    if (to.path === from.path) return
    nuxtApp.hooks.hookOnce('page:finish', () => {
      if (to.hash) return
      if (lenis) lenis.scrollTo(0, { immediate: true })
      else window.scrollTo(0, 0)
    })
  })

  return {
    provide: {
      lenis,
      scrollToTarget: (target: string | HTMLElement) => {
        if (lenis) lenis.scrollTo(target, { offset: -80 })
        else (typeof target === 'string' ? document.querySelector(target) : target)?.scrollIntoView()
      },
    },
  }
})
