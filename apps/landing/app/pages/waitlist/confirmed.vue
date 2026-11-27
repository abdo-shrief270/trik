<script setup lang="ts">
// The API's confirm link lands here once the email is confirmed.
definePageMeta({ heroTone: 'dark' })
usePageSeo('waitlist')
useHead({ meta: [{ name: 'robots', content: 'noindex' }] })

const { t } = useI18n()
const localePath = useLocalePath()
</script>

<template>
  <section class="on-dark relative grid min-h-dvh place-items-center overflow-hidden bg-ink px-5 py-32 text-white">
    <div class="glow start-1/4 top-1/4 size-[520px] bg-indigo/40" aria-hidden="true" />
    <div class="relative max-w-xl text-center">
      <div class="mx-auto grid size-28 place-items-center rounded-[32px] border border-lime/40 bg-white/[.06] shadow-[0_0_80px_-10px_rgb(184_242_91/.35)]">
        <BrandMark tone="ink" :size="76" draw />
      </div>
      <h1 class="mt-10 font-display text-[clamp(40px,6vw,68px)] leading-tight font-bold tracking-[-0.03em]">
        <SplitWords :text="t('waitlist.confirmedTitle')" :delay="700" />
      </h1>
      <p class="mt-5 animate-[word-up_1s_var(--ease-out-expo)_1s_both] text-xl leading-relaxed text-mist">{{ t('waitlist.confirmedBody') }}</p>
      <div class="mt-10 flex animate-[word-up_1s_var(--ease-out-expo)_1.2s_both] flex-col items-center gap-4">
        <p class="text-dusk">{{ t('waitlist.confirmedNext') }}</p>
        <UiButton :to="localePath('/how-it-works')" variant="light" size="lg" arrow>{{ t('cta.seeHow') }}</UiButton>
      </div>
    </div>
  </section>
</template>
