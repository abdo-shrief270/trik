<script setup lang="ts">
const { t, locale } = useI18n()
const reduced = useReducedMotion()
const lang = computed(() => (locale.value === 'ar' ? 'ar' : 'en'))

const sources = computed(() => [
  { key: 'shopify', label: 'Shopify' },
  { key: 'easyorders', label: 'EasyOrders' },
  { key: 'whatsapp', label: t('visual.stores.whatsapp') },
])

// Orders drop into the queue from alternating sources.
interface Item { uid: number; order: number; source: number }
const queue = ref<Item[]>([0, 1, 2].map((i) => ({ uid: i, order: i, source: i })))
let uid = 3
const pulse = ref(-1)

function drop() {
  const source = uid % 3
  pulse.value = source
  setTimeout(() => (pulse.value = -1), 700)
  queue.value.unshift({ uid, order: uid % SAMPLE_ORDERS.length, source })
  uid++
  if (queue.value.length > 4) queue.value.pop()
}

const root = ref<HTMLElement | null>(null)
const inView = ref(false)
useIntersectionObserver(root, ([e]) => (inView.value = !!e?.isIntersecting))
const { pause, resume } = useIntervalFn(drop, 1800, { immediate: false })
watch([inView, reduced], ([v, r]) => (v && !r ? resume() : pause()), { immediate: true })
</script>

<template>
  <div ref="root" class="rounded-[28px] border border-line bg-white p-6 shadow-[0_40px_100px_-50px_rgb(20_18_31/.45)] sm:p-8">
    <div class="grid grid-cols-3 gap-3">
      <div
        v-for="(s, i) in sources"
        :key="s.key"
        class="rounded-xl border px-3 py-3 text-center text-sm font-semibold transition-all duration-500"
        :class="pulse === i ? 'scale-105 border-indigo bg-indigo text-white' : 'border-line bg-paper'"
      >
        {{ s.label }}
      </div>
    </div>

    <svg viewBox="0 0 300 70" class="my-2 h-16 w-full" preserveAspectRatio="none" aria-hidden="true">
      <path v-for="x in [50, 150, 250]" :key="x" :d="`M${x} 0 C ${x} 40, 150 30, 150 70`" class="flow" fill="none" stroke="#4F3BF0" stroke-opacity=".45" stroke-width="2" stroke-dasharray="6 6" />
    </svg>

    <div class="rounded-2xl bg-ink p-4 text-white">
      <div class="flex items-center gap-2 px-1 pb-3 text-sm font-semibold">
        <BrandMark tone="ink" :size="26" />
        {{ t('visual.stores.into') }}
      </div>
      <TransitionGroup tag="ul" class="space-y-2" move-class="transition-transform duration-700 ease-out-expo" enter-active-class="transition duration-700 ease-out-expo" enter-from-class="opacity-0 -translate-y-4 scale-95" leave-active-class="absolute transition duration-300" leave-to-class="opacity-0">
        <li v-for="q in queue" :key="q.uid" class="flex items-center justify-between gap-3 rounded-xl bg-white/[.06] px-4 py-3 text-sm">
          <span class="truncate"><span dir="ltr" class="font-semibold">#{{ SAMPLE_ORDERS[q.order]!.id + q.uid * 7 }}</span> · {{ SAMPLE_ORDERS[q.order]!.name[lang] }}</span>
          <span class="shrink-0 rounded-full bg-white/10 px-2.5 py-0.5 text-xs text-mist">{{ sources[q.source]!.label }}</span>
        </li>
      </TransitionGroup>
    </div>
  </div>
</template>

<style scoped>
.flow { animation: flow 1.2s linear infinite; }
@keyframes flow { to { stroke-dashoffset: -24; } }
</style>
