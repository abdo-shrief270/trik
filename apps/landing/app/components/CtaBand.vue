<script setup lang="ts">
const { t } = useI18n()
const localePath = useLocalePath()
const email = ref('')

// The quick form only carries the email to the full waitlist page.
async function go() {
  await navigateTo({ path: localePath('/waitlist'), query: email.value.trim() ? { email: email.value.trim() } : undefined })
}
</script>

<template>
  <section class="px-5 py-24 sm:px-8">
    <div class="on-dark relative mx-auto max-w-7xl overflow-hidden rounded-[32px] bg-indigo px-6 py-16 text-white sm:px-14 sm:py-20">
      <div class="glow -start-24 -bottom-32 size-96 bg-ink/40" aria-hidden="true" />
      <div class="glow -end-16 -top-24 size-80 bg-white/20" aria-hidden="true" />
      <div class="relative grid items-center gap-10 lg:grid-cols-[1.2fr_1fr]">
        <div>
          <BrandMark tone="indigo" :size="56" class="mb-6" />
          <h2 v-reveal class="font-display text-[clamp(34px,5vw,60px)] leading-[1.05] font-bold tracking-[-0.03em]">{{ t('waitlist.title') }}</h2>
          <p v-reveal="80" class="mt-4 max-w-xl text-lg leading-relaxed text-indigo-soft">{{ t('waitlist.sub') }}</p>
        </div>
        <form v-reveal="160" class="flex flex-col gap-3 sm:flex-row" @submit.prevent="go">
          <label for="cta-email" class="sr-only">{{ t('waitlist.email') }}</label>
          <input
            id="cta-email"
            v-model="email"
            type="email"
            autocomplete="email"
            dir="ltr"
            :placeholder="t('waitlist.compactPlaceholder')"
            class="h-14 min-w-0 flex-1 rounded-lg border-0 bg-white px-5 text-base text-ink placeholder:text-muted rtl:text-right"
          >
          <UiButton type="submit" variant="dark" size="lg" magnetic arrow>{{ t('cta.join') }}</UiButton>
        </form>
      </div>
    </div>
  </section>
</template>
