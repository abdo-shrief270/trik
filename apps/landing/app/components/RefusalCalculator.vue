<script setup lang="ts">
import { TransitionPresets, useTransition } from '@vueuse/core'

const { t } = useI18n()
const reduced = useReducedMotion()

// Example values only; the visitor moves them to their own numbers.
const input = reactive<RefusalInput>({ orders: 1000, rate: 25, ship: 60, ad: 40 })
const cost = computed(() => refusalCost(input))

const duration = computed(() => (reduced.value ? 0 : 700))
const opts = { duration, transition: TransitionPresets.easeOutExpo }
const total = useTransition(() => cost.value.total, opts)
const refused = useTransition(() => cost.value.refused, opts)
const shipping = useTransition(() => cost.value.shipping, opts)
const ads = useTransition(() => cost.value.ads, opts)

const sliders = computed(() => [
  { key: 'orders' as const, label: t('calc.orders'), min: 50, max: 10000, step: 50, suffix: '' },
  { key: 'rate' as const, label: t('calc.rate'), min: 0, max: 60, step: 1, suffix: '%' },
  { key: 'ship' as const, label: t('calc.ship'), min: 20, max: 150, step: 5, suffix: ` ${t('calc.currency')}` },
  { key: 'ad' as const, label: t('calc.ad'), min: 0, max: 200, step: 5, suffix: ` ${t('calc.currency')}` },
])

const fill = (v: number, min: number, max: number) => `${((v - min) / (max - min)) * 100}%`
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[1.1fr_1fr]">
    <div class="space-y-8 rounded-3xl border border-line bg-white p-6 sm:p-10">
      <div v-for="s in sliders" :key="s.key">
        <div class="flex items-baseline justify-between gap-4">
          <label :for="`calc-${s.key}`" class="font-semibold">{{ s.label }}</label>
          <output :for="`calc-${s.key}`" class="font-display text-2xl font-bold tabular-nums" dir="ltr">{{ formatNumber(input[s.key]) }}{{ s.suffix }}</output>
        </div>
        <input
          :id="`calc-${s.key}`"
          v-model.number="input[s.key]"
          type="range"
          :min="s.min"
          :max="s.max"
          :step="s.step"
          class="range mt-4 w-full"
          :style="{ '--fill': fill(input[s.key], s.min, s.max) }"
        >
      </div>
    </div>

    <div class="on-dark relative flex flex-col justify-between gap-10 overflow-hidden rounded-3xl bg-ink p-6 text-white sm:p-10">
      <div class="glow -end-20 -top-20 size-72 bg-indigo/50" aria-hidden="true" />
      <div class="relative">
        <p class="text-mist">{{ t('calc.lost') }}</p>
        <p class="mt-3 font-display text-[clamp(44px,7vw,84px)] leading-none font-bold tracking-[-0.03em] tabular-nums" dir="ltr" aria-live="polite">
          <span class="text-[0.45em] align-top text-mist">EGP</span> {{ formatNumber(total) }}
        </p>
      </div>
      <dl class="relative grid grid-cols-[1fr_auto] gap-x-6 gap-y-4 border-t border-white/10 pt-6">
        <dt class="text-mist">{{ t('calc.refused') }}</dt>
        <dd class="text-end font-semibold tabular-nums" dir="ltr">{{ formatNumber(refused) }}</dd>
        <dt class="text-mist">{{ t('calc.shipping') }}</dt>
        <dd class="text-end font-semibold tabular-nums" dir="ltr">EGP {{ formatNumber(shipping) }}</dd>
        <dt class="text-mist">{{ t('calc.ads') }}</dt>
        <dd class="text-end font-semibold tabular-nums" dir="ltr">EGP {{ formatNumber(ads) }}</dd>
      </dl>
      <p class="relative text-sm leading-relaxed text-dusk">{{ t('calc.disclaimer') }}</p>
    </div>
  </div>
</template>

<style scoped>
.range {
  -webkit-appearance: none;
  appearance: none;
  height: 8px;
  border-radius: 9999px;
  background: linear-gradient(to right, var(--trik-color-indigo) var(--fill), var(--trik-color-border) var(--fill));
  cursor: pointer;
}
[dir='rtl'] .range { background: linear-gradient(to left, var(--trik-color-indigo) var(--fill), var(--trik-color-border) var(--fill)); }
.range::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 28px;
  height: 28px;
  border-radius: 9999px;
  background: #fff;
  border: 3px solid var(--trik-color-indigo);
  box-shadow: 0 4px 14px rgb(79 59 240 / 0.35);
  transition: transform 0.2s;
}
.range::-webkit-slider-thumb:hover, .range:active::-webkit-slider-thumb { transform: scale(1.15); }
.range::-moz-range-thumb {
  width: 24px;
  height: 24px;
  border-radius: 9999px;
  background: #fff;
  border: 3px solid var(--trik-color-indigo);
}
</style>
