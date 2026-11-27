<script setup lang="ts">
usePageSeo('features')
const { t } = useI18n()
const localePath = useLocalePath()
</script>

<template>
  <div>
    <section class="px-5 pt-36 pb-16 sm:px-8 sm:pt-44">
      <div class="mx-auto max-w-7xl">
        <p class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t('features.eyebrow') }}</p>
        <h1 class="mt-4 max-w-4xl font-display text-[clamp(44px,7vw,88px)] leading-[1.02] font-bold tracking-[-0.04em]">
          <SplitWords :text="t('features.title')" />
        </h1>
        <p v-reveal="300" class="mt-6 max-w-2xl text-xl leading-relaxed text-muted">{{ t('features.sub') }}</p>
      </div>
    </section>

    <section v-for="(slug, i) in FEATURE_SLUGS" :key="slug" class="px-5 py-16 sm:px-8 lg:py-24">
      <div class="mx-auto grid max-w-7xl items-center gap-12 lg:grid-cols-2 lg:gap-20">
        <div :class="i % 2 ? 'lg:order-2' : ''">
          <p v-reveal class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t(`features.items.${slug}.eyebrow`) }}</p>
          <h2 v-reveal="80" class="mt-4 font-display text-[clamp(32px,4vw,52px)] leading-[1.08] font-bold tracking-[-0.03em]">{{ t(`features.items.${slug}.headline`) }}</h2>
          <p v-reveal="160" class="mt-5 text-lg leading-relaxed text-muted">{{ t(`features.items.${slug}.sub`) }}</p>
          <div v-reveal="240" class="mt-8">
            <UiButton :to="localePath(`/features/${slug}`)" variant="ghost" arrow>{{ t('cta.learnMore') }}</UiButton>
          </div>
        </div>
        <div v-reveal="120" class="mx-auto w-full max-w-[480px]">
          <FeatureVisual :slug="slug" />
        </div>
      </div>
    </section>

    <CtaBand />
  </div>
</template>
