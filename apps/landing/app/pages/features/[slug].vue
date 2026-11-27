<script setup lang="ts">
const route = useRoute()
const slug = computed(() => String(route.params.slug))
if (!isFeatureSlug(slug.value)) {
  throw createError({ statusCode: 404, statusMessage: 'Not found', fatal: true })
}

const { t } = useI18n()
const localePath = useLocalePath()
const { list } = useCopy()
const feature = computed(() => slug.value as FeatureSlug)
const points = computed(() => list<{ title: string; body: string }>(`features.items.${feature.value}.points`))
const others = computed(() => FEATURE_SLUGS.filter((s) => s !== feature.value))

usePageSeo('features', {
  title: `${t(`features.items.${feature.value}.title`)} · ${t('brand.name')}`,
  description: t(`features.items.${feature.value}.sub`),
})
</script>

<template>
  <div>
    <section class="relative overflow-hidden px-5 pt-36 pb-24 sm:px-8 sm:pt-44">
      <div class="glow -end-40 top-0 size-[520px] bg-indigo/15" aria-hidden="true" />
      <div class="relative mx-auto grid max-w-7xl items-center gap-14 lg:grid-cols-[1.1fr_1fr]">
        <div>
          <NuxtLink :to="localePath('/features')" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo">
            <svg class="size-4 rotate-180 rtl:rotate-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            {{ t('nav.features') }}
          </NuxtLink>
          <p class="mt-8 text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t(`features.items.${feature}.eyebrow`) }}</p>
          <h1 class="mt-4 font-display text-[clamp(40px,6vw,76px)] leading-[1.04] font-bold tracking-[-0.04em]">
            <SplitWords :key="feature" :text="t(`features.items.${feature}.headline`)" />
          </h1>
          <p v-reveal="300" class="mt-6 max-w-xl text-xl leading-relaxed text-muted">{{ t(`features.items.${feature}.sub`) }}</p>
          <div v-reveal="400" class="mt-10 flex flex-wrap gap-3">
            <StartButton :context="t(`features.items.${feature}.title`)" size="lg" magnetic arrow />
            <UiButton :to="localePath('/how-it-works')" variant="ghost" size="lg">{{ t('cta.seeHow') }}</UiButton>
          </div>
        </div>
        <div v-reveal="200" class="mx-auto w-full max-w-[460px]">
          <FeatureVisual :key="feature" :slug="feature" />
        </div>
      </div>
    </section>

    <section class="bg-white px-5 py-24 sm:px-8">
      <ol class="mx-auto grid max-w-7xl gap-6 md:grid-cols-3">
        <li v-for="(p, i) in points" :key="i" v-reveal="i * 100" class="spotlight rounded-3xl border border-line p-8" @pointermove="spotlight">
          <span class="font-display text-4xl font-bold text-indigo tabular-nums" dir="ltr">0{{ i + 1 }}</span>
          <h2 class="mt-6 text-xl font-bold">{{ p.title }}</h2>
          <p class="mt-3 leading-relaxed text-muted">{{ p.body }}</p>
        </li>
      </ol>
    </section>

    <section class="px-5 py-24 sm:px-8">
      <div class="mx-auto max-w-7xl">
        <h2 v-reveal class="font-display text-3xl font-bold">{{ t('features.title') }}</h2>
        <ul class="mt-8 grid gap-4 md:grid-cols-3">
          <li v-for="(s, i) in others" :key="s" v-reveal="i * 80">
            <NuxtLink :to="localePath(`/features/${s}`)" class="group flex h-full flex-col rounded-2xl border border-line bg-white p-6 transition-all duration-500 ease-out-expo hover:-translate-y-1 hover:border-indigo/40">
              <span class="text-lg font-bold">{{ t(`features.items.${s}.title`) }}</span>
              <span class="mt-2 flex-1 text-muted">{{ t(`features.items.${s}.short`) }}</span>
              <span class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-indigo">
                {{ t('cta.learnMore') }}
                <svg class="size-4 transition-transform duration-300 group-hover:translate-x-1 rtl:-scale-x-100 rtl:group-hover:-translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
              </span>
            </NuxtLink>
          </li>
        </ul>
      </div>
    </section>

    <CtaBand />
  </div>
</template>
