<script setup lang="ts">
usePageSeo('how')
const { t } = useI18n()
const localePath = useLocalePath()
const { list } = useCopy()
const steps = computed(() => list<{ title: string; body: string }>('how.steps'))

// The step whose block crosses the middle of the screen drives the sticky panel.
const active = ref(0)
const blocks = ref<HTMLElement[]>([])
onMounted(() => {
  const io = new IntersectionObserver(
    (entries) => {
      for (const e of entries) if (e.isIntersecting) active.value = Number((e.target as HTMLElement).dataset.index)
    },
    { rootMargin: '-45% 0px -45% 0px' },
  )
  blocks.value.forEach((b) => io.observe(b))
  onBeforeUnmount(() => io.disconnect())
})

const rules = computed(() => [
  [t('howVisual.rule1'), t('howVisual.rule1v')],
  [t('howVisual.rule2'), t('howVisual.rule2v')],
  [t('howVisual.rule3'), t('howVisual.rule3v')],
  [t('howVisual.rule4'), t('howVisual.rule4v')],
])
</script>

<template>
  <div>
    <section class="px-5 pt-36 pb-10 sm:px-8 sm:pt-44">
      <div class="mx-auto max-w-7xl">
        <p class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t('how.eyebrow') }}</p>
        <h1 class="mt-4 max-w-4xl font-display text-[clamp(44px,7vw,88px)] leading-[1.02] font-bold tracking-[-0.04em]">
          <SplitWords :text="t('how.title')" />
        </h1>
        <p v-reveal="300" class="mt-6 max-w-2xl text-xl leading-relaxed text-muted">{{ t('how.sub') }}</p>
      </div>
    </section>

    <section class="px-5 pb-24 sm:px-8">
      <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-2 lg:gap-20">
        <ol>
          <li
            v-for="(s, i) in steps"
            :key="i"
            ref="blocks"
            :data-index="i"
            class="flex min-h-[38vh] flex-col justify-center border-s-2 py-10 ps-8 transition-colors duration-500 lg:min-h-[70vh]"
            :class="active === i ? 'border-indigo' : 'border-line'"
          >
            <span class="font-display text-sm font-bold tabular-nums transition-colors duration-500" :class="active === i ? 'text-indigo' : 'text-muted'" dir="ltr">0{{ i + 1 }} / 0{{ steps.length }}</span>
            <h2 class="mt-4 font-display text-[clamp(28px,3.4vw,44px)] leading-tight font-bold transition-opacity duration-500" :class="active === i ? 'opacity-100' : 'lg:opacity-40'">{{ s.title }}</h2>
            <p class="mt-4 max-w-md text-lg leading-relaxed text-muted transition-opacity duration-500" :class="active === i ? 'opacity-100' : 'lg:opacity-40'">{{ s.body }}</p>
          </li>
        </ol>

        <div class="hidden lg:block">
          <div class="sticky top-[calc(50vh-260px)] h-[520px]">
            <div class="on-dark relative h-full overflow-hidden rounded-[32px] bg-ink p-10 text-white">
              <div class="glow -end-20 -top-20 size-80 bg-indigo/40" aria-hidden="true" />
              <Transition mode="out-in" enter-active-class="transition duration-500 ease-out-expo" enter-from-class="opacity-0 translate-y-6 scale-[.98]" leave-active-class="transition duration-300" leave-to-class="opacity-0 -translate-y-4">
                <!-- 1 · WhatsApp -->
                <div v-if="active === 0" key="0" class="relative flex h-full flex-col justify-center gap-5">
                  <div class="rounded-2xl bg-white/[.06] p-6">
                    <div class="text-sm text-mist">{{ t('howVisual.number') }}</div>
                    <div class="mt-2 font-display text-3xl font-bold" dir="ltr">+20 10 •••• 5678</div>
                  </div>
                  <div class="inline-flex items-center gap-3 self-start rounded-full bg-white py-2 ps-3 pe-5 font-semibold text-ink">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                    {{ t('howVisual.connected') }}
                  </div>
                </div>
                <!-- 2 · Store -->
                <div v-else-if="active === 1" key="1" class="relative flex h-full flex-col justify-center gap-4">
                  <div class="text-sm text-mist">{{ t('howVisual.store') }}</div>
                  <div v-for="(s, i) in ['Shopify', 'EasyOrders']" :key="s" class="flex items-center justify-between rounded-2xl bg-white/[.06] px-6 py-5" :style="{ animation: `word-up .7s var(--ease-out-expo) ${i * 150}ms both` }">
                    <span class="text-xl font-bold">{{ s }}</span>
                    <span class="rounded-full bg-white/10 px-3 py-1 text-sm text-mist">{{ t('howVisual.install') }}</span>
                  </div>
                </div>
                <!-- 3 · Rules -->
                <div v-else-if="active === 2" key="2" class="relative flex h-full flex-col justify-center gap-3">
                  <div class="mb-2 text-sm text-mist">{{ t('howVisual.rules') }}</div>
                  <div v-for="([label, value], i) in rules" :key="i" class="flex items-center justify-between rounded-2xl bg-white/[.06] px-6 py-4" :style="{ animation: `word-up .7s var(--ease-out-expo) ${i * 110}ms both` }">
                    <span>{{ label }}</span>
                    <span class="rounded-lg bg-indigo px-3 py-1 font-semibold">{{ value }}</span>
                  </div>
                </div>
                <!-- 4 · Live -->
                <div v-else key="3" class="relative flex h-full items-center justify-center">
                  <div class="w-full max-w-[360px] scale-90">
                    <HeroChat :tilt="false" />
                  </div>
                  <span class="absolute top-0 end-0 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-sm font-semibold">
                    <span class="size-2 animate-pulse rounded-full bg-success" />{{ t('howVisual.live') }}
                  </span>
                </div>
              </Transition>
            </div>
          </div>
        </div>
      </div>
      <p v-reveal class="mx-auto mt-10 max-w-7xl text-lg font-semibold text-indigo">{{ t('how.setupHelp') }}</p>
    </section>

    <div class="px-5 sm:px-8">
      <div class="mx-auto flex max-w-7xl justify-center">
        <UiButton :to="localePath('/features')" variant="ghost" arrow>{{ t('cta.explore') }}</UiButton>
      </div>
    </div>
    <CtaBand />
  </div>
</template>
