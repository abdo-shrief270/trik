<script setup lang="ts">
const { t, locale } = useI18n()
const reduced = useReducedMotion()
const lang = computed(() => (locale.value === 'ar' ? 'ar' : 'en'))

interface Row extends SampleOrder {
  step: LadderStep
  flash: boolean
}

const initial = (): Row[] => SAMPLE_ORDERS.slice(0, 6).map((o) => ({ ...o, step: 'whatsapp', flash: false }))
const rows = ref<Row[]>(initial())

// Confirmed rows sink to the bottom; the rest stay by risk, highest first.
const sorted = computed(() =>
  [...rows.value].sort((a, b) => Number(a.step === 'trikd') - Number(b.step === 'trikd') || b.risk - a.risk),
)
const confirmedCount = computed(() => rows.value.filter((r) => r.step === 'trikd').length)

function flash(row: Row) {
  row.flash = true
  setTimeout(() => (row.flash = false), 900)
}

function confirm(row: Row) {
  if (row.step === 'trikd') return
  row.step = 'trikd'
  flash(row)
}

// Every tick, one open order climbs a step of the ladder.
function tick() {
  const open = rows.value.filter((r) => r.step !== 'trikd')
  if (!open.length) return
  const row = open[Math.floor(Math.random() * open.length)]!
  row.step = nextStep(row.step, row.risk)
  if (row.step === 'trikd') flash(row)
}

const root = ref<HTMLElement | null>(null)
const inView = ref(false)
useIntersectionObserver(root, ([e]) => (inView.value = !!e?.isIntersecting), { threshold: 0.3 })
const { pause, resume } = useIntervalFn(tick, 1700, { immediate: false })
watch([inView, reduced], ([v, r]) => (v && !r ? resume() : pause()), { immediate: true })

function replay() {
  rows.value = initial()
}

const stepClass: Record<LadderStep, string> = {
  whatsapp: 'bg-indigo-soft text-indigo-strong',
  reminder: 'bg-indigo-soft text-indigo-strong',
  deposit: 'bg-warning-bg text-warning',
  call: 'bg-danger-bg text-danger',
  trikd: 'bg-ink text-white',
}
const riskBar: Record<RiskLevel, string> = { high: 'bg-danger', medium: 'bg-[#D97706]', low: 'bg-success' }
</script>

<template>
  <div ref="root" class="overflow-hidden rounded-3xl border border-line bg-white shadow-[0_30px_80px_-40px_rgb(20_18_31/.35)]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
      <div class="flex items-center gap-3">
        <span class="relative flex size-2.5">
          <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60" />
          <span class="relative inline-flex size-2.5 rounded-full bg-success" />
        </span>
        <span class="font-semibold" aria-live="polite">{{ t('board.confirmedCount', { n: confirmedCount, total: rows.length }) }}</span>
      </div>
      <div class="flex items-center gap-3 text-sm text-muted">
        <span>{{ t('board.sample') }}</span>
        <button type="button" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line px-3 font-medium text-ink transition-colors hover:bg-paper" @click="replay">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5" /></svg>
          {{ t('board.reset') }}
        </button>
      </div>
    </div>

    <!-- relative: keeps the sr-only header label inside this scroll box (in RTL it otherwise sits off-screen and widens the page) -->
    <div class="relative overflow-x-auto">
      <!-- Below sm the order and risk columns fold into the customer cell, so Confirm stays on screen. -->
      <table class="w-full text-[15px] sm:min-w-[640px]">
        <thead>
          <tr class="text-start text-[13px] text-muted">
            <th scope="col" class="hidden px-5 py-3 text-start font-medium sm:table-cell sm:px-6">{{ t('board.order') }}</th>
            <th scope="col" class="px-4 py-3 text-start font-medium sm:px-3">{{ t('board.customer') }}</th>
            <th scope="col" class="hidden px-3 py-3 text-start font-medium sm:table-cell">{{ t('board.risk') }}</th>
            <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('board.step') }}</th>
            <th scope="col" class="px-5 py-3 sm:px-6"><span class="sr-only">{{ t('board.confirm') }}</span></th>
          </tr>
        </thead>
        <TransitionGroup tag="tbody" move-class="transition-transform duration-700 ease-out-expo">
          <tr
            v-for="row in sorted"
            :key="row.id"
            class="border-t border-line transition-colors duration-700"
            :class="row.flash ? 'bg-indigo-soft' : row.step === 'trikd' ? 'bg-paper/60' : 'bg-white'"
          >
            <td class="hidden px-5 py-3.5 sm:table-cell sm:px-6">
              <div class="font-semibold text-indigo" dir="ltr">#{{ row.id }}</div>
              <div class="text-[13px] text-muted" dir="ltr">EGP {{ formatNumber(row.amount) }}</div>
            </td>
            <td class="px-4 py-3.5 sm:px-3">
              <div class="font-medium">{{ row.name[lang] }}</div>
              <div class="text-[13px] text-muted">{{ row.city[lang] }}</div>
              <div class="mt-1 flex items-center gap-2 text-[13px] sm:hidden">
                <span class="font-semibold text-indigo" dir="ltr">#{{ row.id }}</span>
                <span class="size-1.5 rounded-full" :class="riskBar[riskLevel(row.risk)]" />
                <span class="whitespace-nowrap text-muted">{{ t('board.risk') }} {{ row.risk }}</span>
              </div>
            </td>
            <td class="hidden px-3 py-3.5 sm:table-cell">
              <div class="flex items-center gap-2">
                <span class="w-7 font-semibold tabular-nums">{{ row.risk }}</span>
                <span class="h-1.5 w-16 overflow-hidden rounded-full bg-line">
                  <span class="block h-full rounded-full" :class="riskBar[riskLevel(row.risk)]" :style="{ width: `${row.risk}%` }" />
                </span>
                <span class="text-[13px] text-muted">{{ t(`board.riskLevels.${riskLevel(row.risk)}`) }}</span>
              </div>
            </td>
            <td class="px-3 py-3.5">
              <Transition mode="out-in" enter-active-class="transition duration-300" enter-from-class="opacity-0 translate-y-1" leave-active-class="transition duration-200" leave-to-class="opacity-0 -translate-y-1">
                <span :key="row.step" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[13px] font-semibold whitespace-nowrap" :class="stepClass[row.step]">
                  <BrandMark v-if="row.step === 'trikd'" tone="ink" :size="20" />
                  {{ t(`board.steps.${row.step}`) }}
                </span>
              </Transition>
            </td>
            <td class="px-4 py-3.5 text-end sm:px-6">
              <button
                v-if="row.step !== 'trikd'"
                type="button"
                class="inline-flex size-11 items-center justify-center gap-1.5 rounded-lg border border-line text-sm font-semibold transition-all hover:border-indigo hover:bg-indigo hover:text-white active:scale-95 sm:h-9 sm:w-auto sm:px-3"
                :aria-label="`${t('board.confirm')} #${row.id}`"
                @click="confirm(row)"
              >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                <span class="hidden sm:inline">{{ t('board.confirm') }}</span>
              </button>
            </td>
          </tr>
        </TransitionGroup>
      </table>
    </div>
  </div>
</template>
