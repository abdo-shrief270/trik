<script setup lang="ts">
import type { NuxtError } from '#app'

defineProps<{ error: NuxtError }>()
const { t } = useI18n()
const localePath = useLocalePath()
useHead({ title: () => t('notFound.title') })
</script>

<template>
  <NuxtLayout>
    <section class="on-dark relative grid min-h-dvh place-items-center overflow-hidden bg-ink px-5 py-32 text-white">
      <div class="glow start-1/3 top-1/4 size-[420px] bg-indigo/40" aria-hidden="true" />
      <div class="relative max-w-xl text-center">
        <div class="mx-auto flex justify-center">
          <BrandMark tone="ink" :size="96" :ticks="2" draw />
        </div>
        <p class="mt-8 font-display text-7xl font-bold text-white/15" dir="ltr">{{ error.statusCode }}</p>
        <h1 class="mt-4 font-display text-[clamp(32px,5vw,52px)] leading-tight font-bold">{{ t('notFound.title') }}</h1>
        <p class="mt-4 text-lg text-mist">{{ t('notFound.body') }}</p>
        <UiButton :to="localePath('/')" variant="light" size="lg" class="mt-10" arrow>{{ t('notFound.back') }}</UiButton>
      </div>
    </section>
  </NuxtLayout>
</template>
