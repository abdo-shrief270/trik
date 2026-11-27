<script setup lang="ts">
// The API's signed verification link redirects here after marking the email verified.
definePageMeta({ layout: 'auth', auth: 'public' })
const { t } = useI18n()
const session = useSessionStore()
useHead({ title: () => `${t('verify.doneTitle')} · ${t('brand.name')}` })
onMounted(() => session.user && session.load().catch(() => {}))
</script>

<template>
  <div class="text-center">
    <div class="mx-auto grid size-20 place-items-center rounded-3xl bg-ink"><BrandMark tone="ink" :size="48" /></div>
    <h1 class="mt-8 font-display text-4xl font-bold tracking-[-0.03em]">{{ t('verify.doneTitle') }}</h1>
    <p class="mt-3 text-muted">{{ t('verify.doneBody') }}</p>
    <UiButton :to="session.user ? '/' : '/sign-in'" size="lg" class="mt-8">{{ session.user ? t('verify.continue') : t('auth.signIn') }}</UiButton>
  </div>
</template>
