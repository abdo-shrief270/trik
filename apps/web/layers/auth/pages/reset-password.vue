<script setup lang="ts">
// Opened from the reset email: /reset-password?token=…&email=… (Identity module, ResetPassword::createUrlUsing).
definePageMeta({ layout: 'auth', auth: 'public' })
const { t } = useI18n()
const route = useRoute()
useHead({ title: () => `${t('auth.resetTitle')} · ${t('brand.name')}` })

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))
const password = ref('')
const confirmation = ref('')
const errors = reactive<{ password?: string; confirmation?: string }>({})
const failure = ref('')
const busy = ref(false)

async function submit() {
  failure.value = ''
  const p = checkNewPassword(password.value)
  errors.password = p ? t(`forms.${p}`) : undefined
  errors.confirmation = confirmation.value !== password.value ? t('forms.passwordMismatch') : undefined
  if (errors.password || errors.confirmation) return
  busy.value = true
  try {
    await call(useApi().POST('/auth/reset-password', { body: { token: token.value, email: email.value, password: password.value, password_confirmation: confirmation.value } }), { quiet401: true })
    await navigateTo({ path: '/sign-in', query: { reset: '1', email: email.value } })
  } catch (e) {
    // A used or expired token comes back as a 422 on `email`.
    failure.value = t(e instanceof ApiError && e.field('email') ? 'auth.resetInvalid' : e instanceof ApiError ? problemMessageKey(e.problem) : 'errors.generic')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="font-display text-4xl font-bold tracking-[-0.03em]">{{ t('auth.resetTitle') }}</h1>

    <template v-if="!token || !email">
      <p role="alert" class="mt-6 rounded-xl bg-danger-bg px-4 py-3 text-[15px] text-danger">{{ t('auth.resetInvalid') }}</p>
      <UiButton to="/forgot-password" block class="mt-6">{{ t('auth.sendLink') }}</UiButton>
    </template>

    <template v-else>
      <p class="mt-3 text-muted">{{ t('auth.resetSub') }} <span dir="ltr" class="font-semibold text-ink">{{ email }}</span></p>
      <p v-if="failure" role="alert" class="mt-6 rounded-xl bg-danger-bg px-4 py-3 text-[15px] text-danger">
        {{ failure }}
        <NuxtLink v-if="failure === t('auth.resetInvalid')" :to="{ path: '/forgot-password', query: { email } }" class="ms-1 font-semibold underline">{{ t('auth.sendLink') }}</NuxtLink>
      </p>
      <form class="mt-8 space-y-5" novalidate @submit.prevent="submit">
        <UiInput v-model="password" :label="t('auth.newPassword')" type="password" autocomplete="new-password" dir="ltr" :hint="t('auth.passwordHint')" :error="errors.password" />
        <UiInput v-model="confirmation" :label="t('auth.passwordConfirm')" type="password" autocomplete="new-password" dir="ltr" :error="errors.confirmation" />
        <UiButton type="submit" size="lg" block :loading="busy">{{ t('auth.resetSave') }}</UiButton>
      </form>
    </template>
  </div>
</template>
