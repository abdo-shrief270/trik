<script setup lang="ts">
definePageMeta({ layout: 'auth', auth: 'guest' })
const { t } = useI18n()
const route = useRoute()
const session = useSessionStore()
useHead({ title: () => `${t('auth.signIn')} · ${t('brand.name')}` })

const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const password = ref('')
const errors = reactive<{ email?: string; password?: string }>({})
const failure = ref('')
const busy = ref(false)
const emailInput = ref<{ focus: () => void } | null>(null)

async function submit() {
  failure.value = ''
  errors.email = checkEmail(email.value) ? t(`forms.${checkEmail(email.value)}`) : undefined
  errors.password = password.value ? undefined : t('forms.required')
  if (errors.email || errors.password) return

  busy.value = true
  try {
    await session.signIn(email.value.trim(), password.value)
    await navigateTo(safeNext(route.query.next))
  } catch (e) {
    failure.value = t(e instanceof ApiError ? signInFailureKey(e.problem) : 'errors.generic')
    password.value = ''
  } finally {
    busy.value = false
  }
}
onMounted(() => emailInput.value?.focus())
</script>

<template>
  <div>
    <h1 class="font-display text-4xl font-bold tracking-[-0.03em]">{{ t('auth.signInTitle') }}</h1>
    <p class="mt-3 text-muted">{{ t('auth.signInSub') }}</p>

    <p v-if="route.query.reset === '1'" role="status" class="mt-6 rounded-xl bg-success-bg px-4 py-3 text-[15px] text-success">{{ t('auth.resetDone') }}</p>
    <p v-if="failure" role="alert" class="mt-6 flex items-start gap-2 rounded-xl bg-danger-bg px-4 py-3 text-[15px] text-danger"><UiIcon name="alert" :size="18" class="mt-0.5" />{{ failure }}</p>

    <form class="mt-8 space-y-5" novalidate @submit.prevent="submit">
      <UiInput ref="emailInput" v-model="email" :label="t('auth.email')" type="email" autocomplete="email" dir="ltr" :error="errors.email" />
      <UiInput v-model="password" :label="t('auth.password')" type="password" autocomplete="current-password" dir="ltr" :error="errors.password" />
      <div class="flex justify-end">
        <NuxtLink :to="{ path: '/forgot-password', query: email ? { email } : {} }" class="text-sm font-semibold text-indigo hover:text-indigo-strong">{{ t('auth.forgot') }}</NuxtLink>
      </div>
      <UiButton type="submit" size="lg" block :loading="busy">{{ t('auth.signIn') }}</UiButton>
    </form>

    <p class="mt-8 text-center text-muted">
      {{ t('auth.noAccount') }}
      <NuxtLink :to="{ path: '/sign-up', query: route.query.next ? { next: route.query.next } : {} }" class="font-semibold text-indigo hover:text-indigo-strong">{{ t('auth.signUp') }}</NuxtLink>
    </p>
  </div>
</template>
