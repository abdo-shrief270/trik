<script setup lang="ts">
const { t, locale, locales, setLocale } = useI18n()
const other = computed(() => locales.value.find((l) => l.code !== locale.value))
</script>

<template>
  <div class="grid min-h-dvh lg:grid-cols-[1fr_1.05fr]">
    <div class="flex flex-col px-6 py-8 sm:px-12">
      <header class="flex items-center justify-between">
        <span class="flex items-center gap-2.5" dir="ltr">
          <BrandMark :size="34" />
          <span class="font-display text-2xl font-bold tracking-[-0.04em]" lang="en">trik</span>
        </span>
        <button v-if="other" type="button" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-[15px] font-medium text-muted hover:text-ink" @click="setLocale(other.code)">
          <UiIcon name="globe" :size="18" /><span :lang="other.code">{{ other.name }}</span>
        </button>
      </header>
      <main id="main" class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-10">
        <slot />
      </main>
    </div>

    <aside class="on-dark relative hidden overflow-hidden bg-ink p-12 text-white lg:flex lg:flex-col lg:justify-end" aria-hidden="true">
      <div class="absolute -end-24 -top-24 size-[520px] rounded-full bg-indigo/40 [mask-image:radial-gradient(closest-side,#000,transparent)]" />
      <div class="relative">
        <BrandMark tone="ink" :size="96" />
        <p class="mt-10 max-w-md font-display text-5xl leading-[1.1] font-bold tracking-[-0.03em]">{{ t('auth.panelTitle') }}</p>
        <p class="mt-5 max-w-md text-lg leading-relaxed text-mist">{{ t('auth.panelBody') }}</p>
      </div>
    </aside>
    <UiToaster />
  </div>
</template>
