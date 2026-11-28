<script setup lang="ts">
const { t, locale } = useI18n()
const lang = computed(() => (locale.value === 'ar' ? 'ar' : 'en'))

// Each sample order with the state it shows in the strip.
const states = ['trikd', 'awaiting', 'trikd', 'deposit', 'trikd', 'trikd', 'awaiting', 'trikd'] as const
const items = computed(() => SAMPLE_ORDERS.map((o, i) => ({ ...o, state: states[i % states.length]! })))

// Only run the strip while it's on screen.
const root = ref<HTMLElement | null>(null)
const inView = ref(false)
useIntersectionObserver(root, ([e]) => (inView.value = !!e?.isIntersecting))
</script>

<template>
  <div ref="root" class="on-dark relative overflow-hidden border-y border-white/10 bg-ink py-5 text-white" :aria-label="t('ticker.label')" role="region">
    <div class="pointer-events-none absolute inset-y-0 start-0 z-10 w-24 bg-gradient-to-r from-ink to-transparent rtl:bg-gradient-to-l" />
    <div class="pointer-events-none absolute inset-y-0 end-0 z-10 w-24 bg-gradient-to-l from-ink to-transparent rtl:bg-gradient-to-r" />
    <div class="marquee gap-4" :class="{ 'is-paused': !inView }" style="--marquee-speed: 50s" aria-hidden="true">
      <template v-for="copy in 2" :key="copy">
        <div v-for="o in items" :key="`${copy}-${o.id}`" class="flex shrink-0 items-center gap-3 rounded-full border border-white/10 bg-white/[.03] py-2 ps-2 pe-5">
          <span class="grid size-8 place-items-center rounded-full bg-white/10 text-sm font-semibold">{{ o.name[lang].charAt(0) }}</span>
          <span class="text-sm text-mist"><span dir="ltr">#{{ o.id }}</span> · {{ o.city[lang] }}</span>
          <span
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
            :class="o.state === 'trikd' ? 'bg-lime text-ink' : o.state === 'deposit' ? 'bg-indigo text-white' : 'bg-white/10 text-mist'"
          >
            {{ t(`ticker.${o.state}`) }}
          </span>
        </div>
      </template>
    </div>
  </div>
</template>
