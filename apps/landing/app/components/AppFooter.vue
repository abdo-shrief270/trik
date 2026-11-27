<script setup lang="ts">
const { t } = useI18n()
const localePath = useLocalePath()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="on-dark relative overflow-hidden bg-ink text-white">
    <div class="mx-auto max-w-7xl px-5 pt-20 pb-10 sm:px-8">
      <div class="grid gap-12 md:grid-cols-[2fr_1fr_1fr]">
        <div>
          <NuxtLink :to="localePath('/')" class="inline-flex items-center gap-2.5" dir="ltr" :aria-label="t('brand.name')">
            <BrandMark tone="ink" :size="40" />
            <span class="font-display text-3xl font-bold tracking-[-0.04em]" lang="en">trik</span>
          </NuxtLink>
          <p class="mt-5 max-w-sm leading-relaxed text-mist">{{ t('footer.tagline') }}</p>
        </div>
        <div>
          <h2 class="text-sm font-semibold text-dusk">{{ t('footer.product') }}</h2>
          <ul class="mt-4 space-y-3">
            <li v-for="slug in FEATURE_SLUGS" :key="slug">
              <NuxtLink :to="localePath(`/features/${slug}`)" class="text-mist transition-colors hover:text-white">{{ t(`features.items.${slug}.title`) }}</NuxtLink>
            </li>
            <li><NuxtLink :to="localePath('/pricing')" class="text-mist transition-colors hover:text-white">{{ t('nav.pricing') }}</NuxtLink></li>
          </ul>
        </div>
        <div>
          <h2 class="text-sm font-semibold text-dusk">{{ t('footer.company') }}</h2>
          <ul class="mt-4 space-y-3">
            <li><NuxtLink :to="localePath('/how-it-works')" class="text-mist transition-colors hover:text-white">{{ t('nav.howItWorks') }}</NuxtLink></li>
            <li><NuxtLink :to="localePath('/waitlist')" class="text-mist transition-colors hover:text-white">{{ t('cta.join') }}</NuxtLink></li>
            <li><a href="mailto:hello@trik.chat" class="text-mist transition-colors hover:text-white">{{ t('footer.contact') }}</a></li>
          </ul>
        </div>
      </div>

      <p class="pointer-events-none mt-20 select-none font-display text-[22vw] leading-[0.8] font-bold tracking-[-0.06em] text-white/[.04] md:text-[16vw]" aria-hidden="true" lang="en">trik.chat</p>

      <div class="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-white/10 pt-6 text-sm text-dusk">
        <span>{{ t('footer.rights', { year }) }}</span>
      </div>
    </div>
  </footer>
</template>
