<script setup lang="ts">
const { t } = useI18n()
</script>

<template>
  <main class="grid min-h-screen place-items-center">
    <div class="text-center">
      <h1 class="font-display text-6xl font-bold tracking-tight">trik</h1>
      <p class="mt-2 text-lg">{{ t('brand.tagline') }}</p>
    </div>
  </main>
</template>
