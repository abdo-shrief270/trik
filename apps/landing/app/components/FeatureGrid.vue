<script setup lang="ts">
const { t } = useI18n()
const localePath = useLocalePath()

const icons: Record<FeatureSlug, string> = {
  confirmation: 'M20 6 9 17l-5-5',
  risk: 'M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  inbox: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
  stores: 'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
}
</script>

<template>
  <ul class="grid gap-5 sm:grid-cols-2">
    <li v-for="(slug, i) in FEATURE_SLUGS" :key="slug" v-reveal="i * 90">
      <NuxtLink
        :to="localePath(`/features/${slug}`)"
        class="spotlight group flex h-full flex-col rounded-3xl border border-line bg-white p-8 transition-[transform,box-shadow,border-color] duration-500 ease-out-expo hover:-translate-y-1 hover:border-indigo/30 hover:shadow-[0_30px_60px_-30px_rgb(79_59_240/.45)] sm:p-10"
        @pointermove="spotlight"
      >
        <span class="grid size-14 place-items-center rounded-2xl bg-indigo-soft text-indigo transition-transform duration-500 ease-out-expo group-hover:scale-110 group-hover:-rotate-6">
          <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons[slug]" /></svg>
        </span>
        <h3 class="mt-8 font-display text-2xl font-bold">{{ t(`features.items.${slug}.title`) }}</h3>
        <p class="mt-3 flex-1 leading-relaxed text-muted">{{ t(`features.items.${slug}.short`) }}</p>
        <span class="mt-8 inline-flex items-center gap-2 font-semibold text-indigo">
          {{ t('cta.learnMore') }}
          <svg class="size-4 transition-transform duration-300 group-hover:translate-x-1.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-1.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
        </span>
      </NuxtLink>
    </li>
  </ul>
</template>
