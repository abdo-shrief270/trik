<script setup lang="ts">
// The API's unsubscribe link (in every waitlist email) lands here.
usePageSeo('waitlist')
useHead({ meta: [{ name: 'robots', content: 'noindex' }] })

const { t } = useI18n()
const localePath = useLocalePath()
</script>

<template>
  <section class="grid min-h-dvh place-items-center px-5 py-32">
    <div class="max-w-xl text-center">
      <div class="mx-auto flex justify-center">
        <BrandMark :size="72" :ticks="2" draw />
      </div>
      <h1 class="mt-10 font-display text-[clamp(36px,5vw,56px)] leading-tight font-bold tracking-[-0.03em]">
        <SplitWords :text="t('waitlist.unsubscribedTitle')" :delay="400" />
      </h1>
      <p class="mt-5 animate-[word-up_1s_var(--ease-out-expo)_.8s_both] text-xl leading-relaxed text-muted">{{ t('waitlist.unsubscribedBody') }}</p>
      <UiButton :to="localePath('/waitlist')" variant="ghost" size="lg" class="mt-10 animate-[word-up_1s_var(--ease-out-expo)_1s_both]">{{ t('waitlist.rejoin') }}</UiButton>
    </div>
  </section>
</template>
