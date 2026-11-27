<script setup lang="ts">
// lang and dir follow the chosen language (Arabic: rtl). Set directly from the locale: in a client-only app
// useLocaleHead leaves them empty on the first load, so Arabic pages rendered without RTL until a switch.
const { locale, locales } = useI18n()
const current = computed(() => locales.value.find((l) => l.code === locale.value))
useHead({
  htmlAttrs: {
    lang: computed(() => current.value?.language ?? locale.value),
    dir: computed(() => current.value?.dir ?? 'rtl'),
  },
})
</script>

<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
