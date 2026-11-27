<script setup lang="ts">
definePageMeta({ layout: 'auth', auth: 'guest' })
const { t } = useI18n()
const route = useRoute()
useHead({ title: () => `${t('auth.forgotTitle')} · ${t('brand.name')}` })

const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const error = ref<string>()
const failure = ref('')
const sent = ref(false)
const busy = ref(false)

async function submit() {
  failure.value = ''
  const e = checkEmail(email.value)
  error.value = e ? t(`forms.${e}`) : undefined
  if (e) return
  busy.value = true
  try {
    // The API answers the same for any email (no account enumeration); so do we.
    await call(useApi().POST('/auth/forgot-password', { body: { email: email.value.trim() } }), { quiet401: true })
    sent.value = true
  } catch (err) {
    failure.value = t(err instanceof ApiError ? problemMessageKey(err.problem) : 'errors.generic')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="font-display text-4xl font-bold tracking-[-0.03em]">{{ t('auth.forgotTitle') }}</h1>

    <template v-if="sent">
      <div role="status" class="mt-8 rounded-2xl border border-line bg-white p-6">
        <UiIcon name="mail" :size="28" class="text-indigo" />
        <p class="mt-4 text-lg font-semibold">{{ t('auth.forgotSentTitle') }}</p>
        <p class="mt-2 leading-relaxed text-muted">{{ t('auth.forgotSent', { email }) }}</p>
      </div>
      <UiButton to="/sign-in" variant="secondary" block class="mt-6">{{ t('auth.backToSignIn') }}</UiButton>
    </template>

    <template v-else>
      <p class="mt-3 text-muted">{{ t('auth.forgotSub') }}</p>
      <p v-if="failure" role="alert" class="mt-6 rounded-xl bg-danger-bg px-4 py-3 text-[15px] text-danger">{{ failure }}</p>
      <form class="mt-8 space-y-5" novalidate @submit.prevent="submit">
        <UiInput v-model="email" :label="t('auth.email')" type="email" autocomplete="email" dir="ltr" :error="error" />
        <UiButton type="submit" size="lg" block :loading="busy">{{ t('auth.sendLink') }}</UiButton>
      </form>
      <p class="mt-8 text-center"><NuxtLink to="/sign-in" class="font-semibold text-indigo hover:text-indigo-strong">{{ t('auth.backToSignIn') }}</NuxtLink></p>
    </template>
  </div>
</template>
