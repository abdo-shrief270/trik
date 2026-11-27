<script setup lang="ts">
usePageSeo('pricing')
const { t } = useI18n()
const { list } = useCopy()

const plans = computed(() => list<{ name: string; for: string; features: string[] }>('pricing.plans'))
const faq = computed(() => list<{ q: string; a: string }>('pricing.faq'))

const orders = ref(800)
const pick = computed(() => planFor(orders.value))
const fill = computed(() => `${((orders.value - 50) / (8000 - 50)) * 100}%`)

const open = ref<number | null>(0)
</script>

<template>
  <div>
    <section class="px-5 pt-36 pb-12 sm:px-8 sm:pt-44">
      <div class="mx-auto max-w-7xl text-center">
        <p class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t('pricing.eyebrow') }}</p>
        <h1 class="mx-auto mt-4 max-w-4xl font-display text-[clamp(44px,7vw,88px)] leading-[1.02] font-bold tracking-[-0.04em]">
          <SplitWords :text="t('pricing.title')" />
        </h1>
        <p v-reveal="300" class="mx-auto mt-6 max-w-2xl text-xl leading-relaxed text-muted">{{ t('pricing.sub') }}</p>
      </div>
    </section>

    <!-- Plan finder -->
    <section class="px-5 sm:px-8">
      <div v-reveal="200" class="mx-auto max-w-3xl rounded-3xl border border-line bg-white p-6 sm:p-8">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <label for="finder" class="font-semibold">{{ t('pricing.finderLabel') }}</label>
          <output for="finder" class="font-display text-2xl font-bold tabular-nums">{{ t('pricing.finderValue', { n: formatNumber(orders) }) }}</output>
        </div>
        <input id="finder" v-model.number="orders" type="range" min="50" max="8000" step="50" class="finder mt-5 w-full" :style="{ '--fill': fill }">
      </div>
    </section>

    <section class="px-5 py-16 sm:px-8">
      <ul class="mx-auto grid max-w-7xl gap-5 lg:grid-cols-3">
        <li
          v-for="(p, i) in plans"
          :key="i"
          v-reveal="i * 100"
          class="relative flex flex-col rounded-3xl border-2 p-8 transition-[transform,border-color,box-shadow,background-color] duration-500 ease-out-expo"
          :class="pick === i ? 'on-dark -translate-y-2 border-ink bg-ink text-white shadow-[0_40px_80px_-40px_rgb(20_18_31/.6)]' : 'border-line bg-white'"
        >
          <Transition enter-active-class="transition duration-500 ease-out-expo" enter-from-class="opacity-0 scale-75">
            <span v-if="pick === i" class="absolute -top-3.5 start-8 inline-flex items-center gap-1.5 rounded-full bg-indigo px-3 py-1 text-sm font-bold text-white">
              {{ t('pricing.recommended') }}
            </span>
          </Transition>
          <h2 class="font-display text-3xl font-bold">{{ p.name }}</h2>
          <p class="mt-1" :class="pick === i ? 'text-mist' : 'text-muted'">{{ p.for }}</p>
          <div class="mt-8">
            <div class="font-display text-4xl font-bold">{{ t('pricing.priceSoon') }}</div>
            <div class="mt-2 text-sm font-semibold" :class="pick === i ? 'text-mist' : 'text-indigo'">{{ t('pricing.bundle', { n: formatNumber(PLAN_BUNDLES[i] ?? 0) }) }}</div>
          </div>
          <ul class="mt-8 flex-1 space-y-3">
            <li v-for="(f, j) in p.features" :key="j" class="flex items-start gap-3">
              <svg class="mt-0.5 size-5 shrink-0" :class="pick === i ? 'text-lime' : 'text-indigo'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
              <span>{{ f }}</span>
            </li>
          </ul>
          <StartButton :context="p.name" :variant="pick === i ? 'light' : 'ghost'" class="mt-10 w-full" />
        </li>
      </ul>
      <p class="mx-auto mt-6 max-w-7xl text-center text-sm text-muted">{{ t('pricing.priceNote') }}</p>
    </section>

    <!-- FAQ -->
    <section class="bg-white px-5 py-24 sm:px-8">
      <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[1fr_2fr]">
        <h2 v-reveal class="font-display text-[clamp(32px,4vw,52px)] font-bold tracking-[-0.03em]">{{ t('pricing.faqTitle') }}</h2>
        <ul class="divide-y divide-line border-y border-line">
          <li v-for="(f, i) in faq" :key="i" v-reveal="i * 60">
            <h3>
              <button
                :id="`faq-q-${i}`"
                type="button"
                class="flex w-full items-center justify-between gap-6 py-6 text-start text-lg font-semibold"
                :aria-expanded="open === i"
                :aria-controls="`faq-a-${i}`"
                @click="open = open === i ? null : i"
              >
                {{ f.q }}
                <span class="grid size-9 shrink-0 place-items-center rounded-full border border-line transition-transform duration-500 ease-out-expo" :class="open === i ? 'rotate-45 bg-ink text-white' : ''">
                  <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                </span>
              </button>
            </h3>
            <div :id="`faq-a-${i}`" role="region" :aria-labelledby="`faq-q-${i}`" class="grid transition-[grid-template-rows] duration-500 ease-out-expo" :class="open === i ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
              <div class="overflow-hidden">
                <p class="pb-6 text-lg leading-relaxed text-muted">{{ f.a }}</p>
              </div>
            </div>
          </li>
        </ul>
      </div>
    </section>

    <CtaBand />
  </div>
</template>

<style scoped>
.finder {
  -webkit-appearance: none;
  appearance: none;
  height: 8px;
  border-radius: 9999px;
  background: linear-gradient(to right, var(--trik-color-indigo) var(--fill), var(--trik-color-border) var(--fill));
  cursor: pointer;
}
[dir='rtl'] .finder { background: linear-gradient(to left, var(--trik-color-indigo) var(--fill), var(--trik-color-border) var(--fill)); }
.finder::-webkit-slider-thumb { -webkit-appearance: none; width: 28px; height: 28px; border-radius: 9999px; background: #fff; border: 3px solid var(--trik-color-indigo); box-shadow: 0 4px 14px rgb(79 59 240 / .35); }
.finder::-moz-range-thumb { width: 24px; height: 24px; border-radius: 9999px; background: #fff; border: 3px solid var(--trik-color-indigo); }
</style>
