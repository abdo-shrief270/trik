<script setup lang="ts">
definePageMeta({ heroTone: 'dark' })
usePageSeo('waitlist')
useHead({ meta: [{ name: 'robots', content: 'noindex' }] })

const { t } = useI18n()
const localePath = useLocalePath()
const route = useRoute()
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))
</script>

<template>
  <section class="on-dark relative grid min-h-dvh place-items-center overflow-hidden bg-ink px-5 py-32 text-white">
    <div class="glow start-1/4 top-1/4 size-[480px] bg-indigo/40" aria-hidden="true" />
    <div class="relative max-w-xl text-center">
      <div class="mx-auto grid size-24 place-items-center rounded-3xl bg-white/[.06]">
        <BrandMark tone="ink" :size="64" draw />
      </div>
      <h1 class="mt-10 font-display text-[clamp(40px,6vw,64px)] leading-tight font-bold tracking-[-0.03em]">
        <SplitWords :text="t('waitlist.checkTitle')" :delay="400" />
      </h1>
      <p class="mt-5 animate-[word-up_1s_var(--ease-out-expo)_.8s_both] text-xl leading-relaxed text-mist">
        {{ t('waitlist.checkBody', { email: email || '…' }) }}
      </p>
      <p class="mt-10 animate-[word-up_1s_var(--ease-out-expo)_1s_both] text-dusk">
        {{ t('waitlist.checkHint') }}
        <NuxtLink :to="localePath('/waitlist')" class="font-semibold text-white underline underline-offset-4">{{ t('waitlist.checkRetry') }}</NuxtLink>.
      </p>
    </div>
  </section>
</template>
