<script setup lang="ts">
definePageMeta({ heroTone: 'dark' })
usePageSeo('home')

const { t } = useI18n()
const localePath = useLocalePath()
const { list } = useCopy()
const problems = computed(() => list<{ title: string; body: string }>('problem.items'))
</script>

<template>
  <div>
    <!-- HERO -->
    <section class="on-dark relative overflow-hidden bg-ink pt-32 pb-20 text-white sm:pt-40 lg:pb-28">
      <div class="glow -start-40 top-20 size-[560px] bg-indigo/40" aria-hidden="true" />
      <div class="glow -end-32 bottom-0 size-[420px] bg-indigo/25" style="animation-delay: -6s" aria-hidden="true" />
      <div
        class="pointer-events-none absolute inset-0 opacity-[.07] [background-image:linear-gradient(white_1px,transparent_1px),linear-gradient(90deg,white_1px,transparent_1px)] [background-size:64px_64px] [mask-image:radial-gradient(ellipse_at_center,black_20%,transparent_70%)]"
        aria-hidden="true"
      />

      <div class="relative mx-auto grid max-w-7xl items-center gap-16 px-5 sm:px-8 lg:grid-cols-[1.15fr_1fr]">
        <div>
          <p class="inline-flex animate-[word-up_.8s_var(--ease-out-expo)_both] items-center gap-2 rounded-full border border-white/15 bg-white/5 py-1.5 ps-2 pe-4 text-sm font-medium text-mist">
            <BrandMark tone="ink" :size="26" draw />
            {{ t('hero.eyebrow') }}
          </p>
          <h1 class="mt-8 font-display text-[clamp(44px,6.4vw,92px)] leading-[1.08] font-bold tracking-[-0.04em]">
            <SplitWords :text="t('hero.line1')" :delay="150" />
            <br>
            <span class="relative inline-block">
              <SplitWords :text="t('hero.line2')" :delay="450" />
              <svg class="absolute -bottom-3 start-0 h-4 w-full text-lime" viewBox="0 0 300 16" preserveAspectRatio="none" aria-hidden="true">
                <path class="underline-draw" d="M3 11 C 80 3, 200 3, 297 9" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" />
              </svg>
            </span>
          </h1>
          <p class="mt-8 max-w-xl animate-[word-up_1s_var(--ease-out-expo)_.7s_both] text-lg leading-relaxed text-mist sm:text-xl">{{ t('hero.sub') }}</p>
          <div class="mt-10 flex animate-[word-up_1s_var(--ease-out-expo)_.85s_both] flex-wrap gap-3">
            <StartButton variant="light" size="lg" magnetic arrow />
            <UiButton :to="localePath('/how-it-works')" variant="ghost-dark" size="lg">{{ t('cta.seeHow') }}</UiButton>
          </div>
          <p class="mt-6 animate-[word-up_1s_var(--ease-out-expo)_1s_both] text-sm text-dusk">{{ t('hero.note') }}</p>
        </div>

        <div class="animate-[word-up_1.2s_var(--ease-out-expo)_.5s_both]">
          <HeroChat />
        </div>
      </div>
    </section>

    <OrderTicker />

    <!-- PROBLEM -->
    <section class="px-5 py-28 sm:px-8 lg:py-36">
      <div class="mx-auto grid max-w-7xl gap-14 lg:grid-cols-[1fr_1.3fr]">
        <div class="lg:sticky lg:top-32 lg:self-start">
          <p v-reveal class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t('problem.eyebrow') }}</p>
          <h2 v-reveal="80" class="mt-4 font-display text-[clamp(36px,4.6vw,60px)] leading-[1.05] font-bold tracking-[-0.03em]">{{ t('problem.title') }}</h2>
        </div>
        <ol class="space-y-5">
          <li v-for="(p, i) in problems" :key="i" v-reveal="i * 100" class="rounded-3xl border border-line bg-white p-8 sm:p-10">
            <span class="font-display text-5xl font-bold text-indigo/20 tabular-nums" dir="ltr">0{{ i + 1 }}</span>
            <h3 class="mt-4 text-2xl font-bold">{{ p.title }}</h3>
            <p class="mt-3 text-lg leading-relaxed text-muted">{{ p.body }}</p>
          </li>
        </ol>
      </div>
    </section>

    <RoadSection />

    <!-- LIVE BOARD -->
    <section class="px-5 py-28 sm:px-8 lg:py-36">
      <div class="mx-auto max-w-7xl">
        <div class="max-w-3xl">
          <p v-reveal class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t('board.eyebrow') }}</p>
          <h2 v-reveal="80" class="mt-4 font-display text-[clamp(36px,4.6vw,60px)] leading-[1.05] font-bold tracking-[-0.03em]">{{ t('board.title') }}</h2>
          <p v-reveal="160" class="mt-5 text-lg leading-relaxed text-muted">{{ t('board.sub') }}</p>
        </div>
        <div v-reveal="200" class="mt-12">
          <BoardDemo />
        </div>
      </div>
    </section>

    <!-- CALCULATOR -->
    <section id="calculator" class="bg-white px-5 py-28 sm:px-8 lg:py-36">
      <div class="mx-auto max-w-7xl">
        <div class="max-w-3xl">
          <p v-reveal class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t('calc.eyebrow') }}</p>
          <h2 v-reveal="80" class="mt-4 font-display text-[clamp(36px,4.6vw,60px)] leading-[1.05] font-bold tracking-[-0.03em]">{{ t('calc.title') }}</h2>
          <p v-reveal="160" class="mt-5 text-lg leading-relaxed text-muted">{{ t('calc.sub') }}</p>
        </div>
        <div v-reveal="200" class="mt-12">
          <RefusalCalculator />
        </div>
      </div>
    </section>

    <!-- FEATURES -->
    <section class="px-5 py-28 sm:px-8 lg:py-36">
      <div class="mx-auto max-w-7xl">
        <div class="flex flex-wrap items-end justify-between gap-6">
          <div class="max-w-3xl">
            <p v-reveal class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t('features.eyebrow') }}</p>
            <h2 v-reveal="80" class="mt-4 font-display text-[clamp(36px,4.6vw,60px)] leading-[1.05] font-bold tracking-[-0.03em]">{{ t('features.title') }}</h2>
            <p v-reveal="160" class="mt-5 text-lg leading-relaxed text-muted">{{ t('features.sub') }}</p>
          </div>
          <UiButton :to="localePath('/features')" variant="ghost" arrow>{{ t('cta.explore') }}</UiButton>
        </div>
        <div class="mt-12">
          <FeatureGrid />
        </div>
      </div>
    </section>

    <CtaBand />
  </div>
</template>

<style scoped>
.underline-draw { stroke-dasharray: 320; stroke-dashoffset: 320; animation: underline 1.1s var(--ease-out-expo) 1s forwards; }
@keyframes underline { to { stroke-dashoffset: 0; } }
</style>
