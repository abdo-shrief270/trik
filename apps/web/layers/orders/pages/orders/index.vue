<script setup lang="ts">
// The orders list lands in the orders PR.
const { t } = useI18n()
useHead({ title: () => `${t('nav.orders')} · ${t('brand.name')}` })
</script>

<template>
  <section class="flex flex-1 items-center justify-center">
    <UiEmptyState icon="orders" :title="t('placeholder.orders.title')" :body="t('placeholder.orders.body')" />
  </section>
</template>
