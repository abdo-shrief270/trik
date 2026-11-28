<script setup lang="ts">
const { t } = useI18n()
</script>

<template>
  <section class="px-5 py-24 sm:px-8">
    <div class="on-dark relative mx-auto max-w-7xl overflow-hidden rounded-[32px] bg-indigo px-6 py-16 text-white sm:px-14 sm:py-20">
      <div class="glow -start-24 -bottom-32 size-96 bg-ink/40" aria-hidden="true" />
      <div class="glow -end-16 -top-24 size-80 bg-white/20" aria-hidden="true" />
      <div class="relative grid items-center gap-10 lg:grid-cols-[1.2fr_1fr]">
        <div>
          <BrandMark tone="indigo" :size="56" class="mb-6" />
          <h2 v-reveal class="font-display text-[clamp(34px,5vw,60px)] leading-[1.05] font-bold tracking-[-0.03em]">{{ t('ctaBand.title') }}</h2>
          <p v-reveal="80" class="mt-4 max-w-xl text-lg leading-relaxed text-indigo-soft">{{ t('ctaBand.sub') }}</p>
        </div>
        <div v-reveal="160" class="flex flex-col items-start gap-4 lg:items-end">
          <StartButton variant="dark" size="lg" magnetic arrow />
          <p class="text-sm text-indigo-soft">{{ t('ctaBand.note') }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
