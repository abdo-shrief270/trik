<script setup lang="ts">
// The confirmation board lands in the confirmations PR.
const { t } = useI18n()
useHead({ title: () => `${t('nav.confirmations')} · ${t('brand.name')}` })
</script>

<template>
  <section class="flex flex-1 items-center justify-center">
    <UiEmptyState icon="checkCircle" :title="t('placeholder.confirmations.title')" :body="t('placeholder.confirmations.body')" />
  </section>
</template>
