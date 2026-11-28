<script setup lang="ts">
import { TransitionPresets, useTransition } from '@vueuse/core'

const { t } = useI18n()
const { list } = useCopy()
const reasons = computed(() => list('visual.risk.reasons'))
const reduced = useReducedMotion()

const root = ref<HTMLElement | null>(null)
const shown = ref(false)
const paid = ref(false)
useIntersectionObserver(root, ([e]) => {
  if (e?.isIntersecting && !shown.value) {
    shown.value = true
    setTimeout(() => (paid.value = true), reduced.value ? 0 : 3200)
  }
}, { threshold: 0.4 })

const SCORE = 72
const score = useTransition(() => (shown.value ? SCORE : 0), { duration: computed(() => (reduced.value ? 0 : 1600)), transition: TransitionPresets.easeOutExpo })

// Half-circle gauge, 0–100.
const ARC = Math.PI * 90
const dash = computed(() => `${(score.value / 100) * ARC} ${ARC}`)
</script>

<template>
  <div ref="root" class="relative rounded-[28px] border border-line bg-white p-6 shadow-[0_40px_100px_-50px_rgb(20_18_31/.45)] sm:p-8">
    <div class="flex items-center justify-between">
      <div class="font-semibold" dir="ltr">#1048 · EGP 1,250</div>
      <span class="rounded-full bg-danger-bg px-3 py-1 text-sm font-semibold text-danger">{{ t('board.riskLevels.high') }}</span>
    </div>

    <div class="relative mx-auto mt-6 w-full max-w-[300px]" dir="ltr">
      <svg viewBox="0 0 200 110" class="w-full" aria-hidden="true">
        <path d="M10 100 A90 90 0 0 1 190 100" fill="none" stroke="#E5E7EB" stroke-width="14" stroke-linecap="round" />
        <path d="M10 100 A90 90 0 0 1 190 100" fill="none" stroke="url(#risk-grad)" stroke-width="14" stroke-linecap="round" :stroke-dasharray="dash" />
        <defs>
          <linearGradient id="risk-grad" x1="0" x2="1">
            <stop offset="0" stop-color="#146C3F" />
            <stop offset=".55" stop-color="#D97706" />
            <stop offset="1" stop-color="#B42318" />
          </linearGradient>
        </defs>
      </svg>
      <div class="absolute inset-x-0 bottom-0 text-center">
        <div class="font-display text-6xl leading-none font-bold tabular-nums">{{ Math.round(score) }}</div>
        <div class="mt-1 text-sm text-muted">{{ t('visual.risk.label') }} · {{ t('visual.risk.of') }}</div>
      </div>
    </div>

    <ul class="mt-8 space-y-2.5">
      <li
        v-for="(r, i) in reasons"
        :key="i"
        class="flex items-center gap-3 rounded-xl bg-paper px-4 py-3 text-[15px] transition-all duration-700 ease-out-expo"
        :class="shown ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0'"
        :style="{ transitionDelay: `${600 + i * 250}ms` }"
      >
        <span class="size-2 shrink-0 rounded-full bg-danger" />
        {{ r }}
      </li>
    </ul>

    <div class="mt-5 flex items-center justify-between gap-3 rounded-xl border px-4 py-3 transition-colors duration-700" :class="paid ? 'border-ink bg-ink text-white' : 'border-warning/30 bg-warning-bg text-warning'">
      <span class="font-semibold">{{ paid ? t('visual.risk.paid') : t('visual.risk.deposit') }}</span>
      <BrandMark v-if="paid" tone="ink" :size="30" draw />
    </div>
  </div>
</template>
