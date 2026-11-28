<script setup lang="ts">
const { t, locale } = useI18n()
const localePath = useLocalePath()
const switchLocalePath = useSwitchLocalePath()
const route = useRoute()

const otherLocale = computed(() => (locale.value === 'ar' ? 'en' : 'ar'))
const error = useError()
const darkHero = computed(() => route.meta.heroTone === 'dark' || !!error.value)

const { y } = useWindowScroll()
const scrolled = computed(() => y.value > 24)
const onDark = computed(() => darkHero.value && !scrolled.value)

// Hide on scroll down, show on scroll up.
const hidden = ref(false)
let last = 0
watch(y, (now) => {
  hidden.value = now > 400 && now > last && !open.value
  last = now
})

const open = ref(false)
watch(() => route.fullPath, () => (open.value = false))
const bodyLocked = useScrollLock(import.meta.client ? document.body : null)
watch(open, (v) => (bodyLocked.value = v))
onKeyStroke('Escape', () => (open.value = false))

const links = computed(() => [
  { to: localePath('/features'), label: t('nav.features') },
  { to: localePath('/how-it-works'), label: t('nav.howItWorks') },
  { to: localePath('/pricing'), label: t('nav.pricing') },
])
</script>

<template>
  <header
    class="fixed inset-x-0 top-0 z-50 transition-[transform,background-color,box-shadow,color] duration-500 ease-out-expo"
    :class="[
      hidden ? '-translate-y-full' : 'translate-y-0',
      scrolled ? 'bg-paper/80 shadow-[0_1px_0_rgb(20_18_31/.08)] backdrop-blur-xl' : 'bg-transparent',
      onDark ? 'on-dark text-white' : 'text-ink',
    ]"
  >
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:rounded-lg focus:bg-indigo focus:px-4 focus:py-2 focus:text-white">{{ t('nav.skip') }}</a>
    <nav class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-6 px-5 sm:px-8" :aria-label="t('nav.menu')">
      <!-- The mark sits left of the word in both languages (brand book, logo). -->
      <NuxtLink :to="localePath('/')" class="flex items-center gap-2.5" dir="ltr" :aria-label="t('brand.name')">
        <BrandMark :tone="onDark ? 'ink' : 'light'" :size="38" />
        <span class="font-display text-[26px] font-bold tracking-[-0.04em]" lang="en">trik</span>
      </NuxtLink>

      <div class="hidden items-center gap-1 md:flex">
        <NuxtLink
          v-for="l in links"
          :key="l.to"
          :to="l.to"
          class="relative rounded-lg px-4 py-2.5 text-[15px] font-medium opacity-80 transition-opacity hover:opacity-100"
          active-class="!opacity-100 after:absolute after:inset-x-4 after:-bottom-0.5 after:h-0.5 after:rounded-full after:bg-current"
        >
          {{ l.label }}
        </NuxtLink>
      </div>

      <div class="flex items-center gap-2">
        <NuxtLink
          :to="switchLocalePath(otherLocale)"
          :lang="otherLocale"
          class="rounded-lg px-3 py-2.5 text-[15px] font-medium opacity-80 transition-opacity hover:opacity-100"
          :class="otherLocale === 'ar' ? 'font-arabic' : 'font-sans'"
        >
          {{ t('nav.otherLanguage') }}
        </NuxtLink>
        <div class="hidden sm:block">
          <StartButton :variant="onDark ? 'light' : 'primary'" magnetic />
        </div>
        <button
          type="button"
          class="grid size-11 place-items-center rounded-lg md:hidden"
          :aria-expanded="open"
          aria-controls="mobile-menu"
          :aria-label="open ? t('nav.close') : t('nav.menu')"
          @click="open = !open"
        >
          <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path :d="open ? 'M6 6l12 12' : 'M4 8h16'" class="transition-all duration-300" />
            <path :d="open ? 'M18 6L6 18' : 'M4 16h16'" class="transition-all duration-300" />
          </svg>
        </button>
      </div>
    </nav>

    <Transition
      enter-active-class="transition duration-500 ease-out-expo"
      enter-from-class="opacity-0 -translate-y-4"
      leave-active-class="transition duration-300 ease-out-expo"
      leave-to-class="opacity-0 -translate-y-4"
    >
      <div v-if="open" id="mobile-menu" class="fixed inset-x-0 top-18 bottom-0 overflow-y-auto bg-paper px-5 pb-10 text-ink md:hidden">
        <ul class="mt-6 space-y-1">
          <li v-for="(l, i) in links" :key="l.to" class="animate-[word-up_.6s_var(--ease-out-expo)_both]" :style="{ animationDelay: `${80 + i * 60}ms` }">
            <NuxtLink :to="l.to" class="block border-b border-line py-5 font-display text-3xl font-bold">{{ l.label }}</NuxtLink>
          </li>
        </ul>
        <StartButton size="lg" class="mt-8 w-full" />
      </div>
    </Transition>
  </header>
</template>
