<script setup lang="ts">
const { t, locale } = useI18n()
const { list } = useCopy()
const steps = computed(() => list<{ name: string; body: string }>('road.steps'))

const pin = ref<HTMLElement | null>(null)
const track = ref<HTMLElement | null>(null)
const fill = ref<HTMLElement | null>(null)
const active = ref(0)

const MEDIA = '(min-width: 1024px) and (prefers-reduced-motion: no-preference)'
let cleanup: (() => void) | undefined
let refresh: (() => void) | undefined
let unmounted = false

// The pinned, sideways scroll only runs on wide screens with motion allowed; GSAP is loaded only then.
onMounted(async () => {
  if (!window.matchMedia(MEDIA).matches) return
  const [{ gsap }, { ScrollTrigger }] = await Promise.all([import('gsap'), import('gsap/ScrollTrigger')])
  if (unmounted || !pin.value || !track.value || !fill.value) return
  gsap.registerPlugin(ScrollTrigger)

  const lenis = useNuxtApp().$lenis
  const onScroll = () => ScrollTrigger.update()
  lenis?.on('scroll', onScroll)

  const mm = gsap.matchMedia()
  mm.add(MEDIA, () => {
    const rtl = document.documentElement.dir === 'rtl'
    const distance = () => Math.max(0, track.value!.scrollWidth - pin.value!.clientWidth)
    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: pin.value,
        pin: true,
        scrub: 0.8,
        start: 'top top',
        end: () => `+=${distance()}`,
        invalidateOnRefresh: true,
        onUpdate: (self) => (active.value = Math.round(self.progress * (steps.value.length - 1))),
      },
    })
    tl.to(track.value, { x: () => (rtl ? 1 : -1) * distance(), ease: 'none' }, 0)
    tl.fromTo(fill.value, { scaleX: 0 }, { scaleX: 1, ease: 'none' }, 0)
  })
  refresh = () => ScrollTrigger.refresh()
  cleanup = () => {
    lenis?.off('scroll', onScroll)
    mm.revert()
  }
})
onBeforeUnmount(() => {
  unmounted = true
  cleanup?.()
})

// Re-measure when the language (and so the direction and widths) changes.
watch(locale, () => nextTick(() => refresh?.()))
</script>

<template>
  <section class="on-dark relative overflow-hidden bg-ink text-white">
    <div class="glow start-1/4 top-10 size-[480px] bg-indigo/30" aria-hidden="true" />
    <div ref="pin" class="relative flex flex-col justify-center py-24 lg:h-dvh lg:py-0">
      <div class="mx-auto w-full max-w-7xl px-5 sm:px-8">
        <p v-reveal class="text-sm font-semibold tracking-[0.12em] text-dusk uppercase">{{ t('road.eyebrow') }}</p>
        <h2 v-reveal="80" class="mt-4 max-w-3xl font-display text-[clamp(36px,5vw,64px)] leading-[1.05] font-bold tracking-[-0.03em]">{{ t('road.title') }}</h2>
      </div>

      <div class="mt-14 lg:ps-[max(2rem,calc((100vw-80rem)/2+2rem))]">
        <ol ref="track" class="flex flex-col gap-4 px-5 sm:px-8 lg:w-max lg:flex-row lg:gap-6 lg:ps-0 lg:pe-[30vw]">
          <li
            v-for="(s, i) in steps"
            :key="i"
            class="relative rounded-3xl border p-7 transition-[border-color,background-color] duration-500 lg:w-[340px] lg:shrink-0"
            :class="active === i ? (i === 1 ? 'border-lime bg-ink-2' : 'border-indigo bg-ink-2') : 'border-white/10 bg-white/[.02]'"
          >
            <div class="flex items-center justify-between">
              <span class="font-display text-sm font-semibold text-dusk tabular-nums" dir="ltr">{{ String(i + 1).padStart(2, '0') }}</span>
              <BrandMark v-if="i === 1" tone="ink" :size="36" />
            </div>
            <h3 class="mt-10 font-display text-3xl font-bold">{{ s.name }}</h3>
            <p class="mt-3 leading-relaxed text-mist">{{ s.body }}</p>
          </li>
        </ol>
      </div>

      <div class="mx-auto mt-14 hidden w-full max-w-7xl px-8 lg:block" aria-hidden="true">
        <div class="h-1 overflow-hidden rounded-full bg-white/10">
          <div ref="fill" class="h-full origin-left rounded-full bg-gradient-to-r from-indigo to-lime rtl:origin-right rtl:bg-gradient-to-l" />
        </div>
      </div>
    </div>
  </section>
</template>
