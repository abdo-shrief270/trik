<script setup lang="ts">
definePageMeta({ layout: 'auth', auth: 'guest' })
const { t } = useI18n()
const route = useRoute()
const session = useSessionStore()
useHead({ title: () => `${t('auth.signUp')} · ${t('brand.name')}` })

const form = reactive({ name: '', email: '', password: '', password_confirmation: '', workspace_name: '' })
type Field = keyof typeof form
const errors = reactive<Partial<Record<Field, string>>>({})
const failure = ref('')
const busy = ref(false)

function validate(): boolean {
  const check: Record<Field, FieldError | null> = {
    name: checkName(form.name),
    email: checkEmail(form.email),
    password: checkNewPassword(form.password),
    password_confirmation: !form.password_confirmation ? 'required' : form.password_confirmation !== form.password ? 'passwordMismatch' : null,
    workspace_name: checkName(form.workspace_name),
  }
  for (const k of Object.keys(check) as Field[]) errors[k] = check[k] ? t(`forms.${check[k]}`) : undefined
  return Object.values(check).every((v) => v === null)
}

async function submit() {
  failure.value = ''
  if (!validate()) return focusFirstError()
  busy.value = true
  try {
    await session.signUp({ ...form, name: form.name.trim(), email: form.email.trim(), workspace_name: form.workspace_name.trim() })
    await navigateTo(safeNext(route.query.next))
  } catch (e) {
    if (e instanceof ApiError && e.problem.errors) {
      for (const k of Object.keys(form) as Field[]) {
        const key = serverFieldKey(e.field(k))
        errors[k] = key ? t(key) : undefined
      }
      focusFirstError()
    } else {
      failure.value = t(e instanceof ApiError ? problemMessageKey(e.problem) : 'errors.generic')
    }
  } finally {
    busy.value = false
  }
}

async function focusFirstError() {
  await nextTick()
  document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
}
</script>

<template>
  <div>
    <h1 class="font-display text-4xl font-bold tracking-[-0.03em]">{{ t('auth.signUpTitle') }}</h1>
    <p class="mt-3 text-muted">{{ t('auth.signUpSub') }}</p>
    <p v-if="failure" role="alert" class="mt-6 flex items-start gap-2 rounded-xl bg-danger-bg px-4 py-3 text-[15px] text-danger"><UiIcon name="alert" :size="18" class="mt-0.5" />{{ failure }}</p>

    <form class="mt-8 space-y-5" novalidate @submit.prevent="submit">
      <UiInput v-model="form.name" :label="t('auth.name')" autocomplete="name" :error="errors.name" />
      <UiInput v-model="form.workspace_name" :label="t('auth.workspaceName')" :hint="t('auth.workspaceHint')" autocomplete="organization" :error="errors.workspace_name" />
      <UiInput v-model="form.email" :label="t('auth.email')" type="email" autocomplete="email" dir="ltr" :error="errors.email" />
      <UiInput v-model="form.password" :label="t('auth.password')" type="password" autocomplete="new-password" dir="ltr" :hint="t('auth.passwordHint')" :error="errors.password" />
      <UiInput v-model="form.password_confirmation" :label="t('auth.passwordConfirm')" type="password" autocomplete="new-password" dir="ltr" :error="errors.password_confirmation" />
      <UiButton type="submit" size="lg" block :loading="busy">{{ t('auth.createAccount') }}</UiButton>
    </form>

    <p class="mt-8 text-center text-muted">
      {{ t('auth.haveAccount') }}
      <NuxtLink to="/sign-in" class="font-semibold text-indigo hover:text-indigo-strong">{{ t('auth.signIn') }}</NuxtLink>
    </p>
  </div>
</template>
