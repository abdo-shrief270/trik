<script setup lang="ts">
const { t, locale, locales, setLocale } = useI18n()
const session = useSessionStore()
const toast = useToast()
useHead({ title: () => `${t('nav.settings')} · ${t('brand.name')}` })

const language = computed({
  get: () => locale.value,
  set: (v: string) => setLocale(v as typeof locale.value),
})
const languages = computed(() => locales.value.map((l) => ({ value: l.code, label: l.name ?? l.code })))

const { data: sessions, refresh, status } = useAsyncData('sessions', async () => (await call(useApi().GET('/auth/sessions'))).data ?? [])
const revoking = ref<string | null>(null)

async function revoke(type: 'token' | 'browser', id: string) {
  revoking.value = id
  try {
    await call(useApi().DELETE('/auth/sessions/{type}/{id}', { params: { path: { type, id } } }))
    toast.success(t('settings.sessionEnded'))
    await refresh()
  } catch (e) {
    toast.error(t(e instanceof ApiError ? problemMessageKey(e.problem) : 'errors.generic'))
  } finally {
    revoking.value = null
  }
}

const fmt = (iso: string | null | undefined) =>
  iso ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '—'
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-8 py-10">
    <h1 class="font-display text-4xl font-bold tracking-[-0.03em]">{{ t('nav.settings') }}</h1>

    <section class="mt-10 rounded-2xl border border-line bg-white p-6" aria-labelledby="account">
      <h2 id="account" class="text-lg font-bold">{{ t('settings.account') }}</h2>
      <dl class="mt-5 grid gap-4 sm:grid-cols-2">
        <div><dt class="text-sm text-muted">{{ t('auth.name') }}</dt><dd class="mt-1 font-semibold">{{ session.user?.name }}</dd></div>
        <div>
          <dt class="text-sm text-muted">{{ t('auth.email') }}</dt>
          <dd class="mt-1 flex flex-wrap items-center gap-2 font-semibold">
            <span dir="ltr">{{ session.user?.email }}</span>
            <UiBadge :tone="session.user?.email_verified ? 'success' : 'warning'">{{ session.user?.email_verified ? t('settings.verified') : t('settings.notVerified') }}</UiBadge>
          </dd>
        </div>
        <div><dt class="text-sm text-muted">{{ t('settings.workspace') }}</dt><dd class="mt-1 font-semibold">{{ session.workspace?.name }}</dd></div>
        <div><dt class="text-sm text-muted">{{ t('settings.role') }}</dt><dd class="mt-1 font-semibold">{{ t(`roles.${session.workspace?.role ?? 'agent'}`) }}</dd></div>
      </dl>
    </section>

    <section class="mt-6 rounded-2xl border border-line bg-white p-6" aria-labelledby="lang">
      <h2 id="lang" class="text-lg font-bold">{{ t('settings.language') }}</h2>
      <div class="mt-5 max-w-xs"><UiSelect v-model="language" :label="t('settings.languageLabel')" :options="languages" /></div>
    </section>

    <section class="mt-6 rounded-2xl border border-line bg-white" aria-labelledby="devices">
      <header class="px-6 pt-6">
        <h2 id="devices" class="text-lg font-bold">{{ t('settings.sessions') }}</h2>
        <p class="mt-1 text-muted">{{ t('settings.sessionsSub') }}</p>
      </header>
      <ul class="mt-4 divide-y divide-line border-t border-line">
        <template v-if="status === 'pending'">
          <li v-for="i in 2" :key="i" class="px-6 py-4"><UiSkeleton class="h-5 w-48" /></li>
        </template>
        <li v-for="s in sessions" v-else :key="`${s.type}-${s.id}`" class="flex flex-wrap items-center gap-4 px-6 py-4">
          <UiIcon :name="s.type === 'token' ? 'phone' : 'devices'" :size="22" class="text-muted" />
          <div class="min-w-0 flex-1">
            <p class="flex items-center gap-2 font-semibold">
              {{ s.type === 'token' ? s.name : t('settings.browser') }}
              <UiBadge v-if="s.current" tone="indigo">{{ t('settings.thisDevice') }}</UiBadge>
            </p>
            <p class="text-sm text-muted"><span dir="ltr">{{ s.ip_address ?? '—' }}</span> · {{ fmt(s.last_active_at) }}</p>
          </div>
          <UiButton v-if="!s.current" size="sm" variant="secondary" :loading="revoking === s.id" @click="revoke(s.type!, s.id!)">{{ t('settings.signOutDevice') }}</UiButton>
        </li>
      </ul>
    </section>

    <div class="mt-8">
      <UiButton variant="danger" @click="session.signOut()"><UiIcon name="logout" :size="18" />{{ t('auth.signOut') }}</UiButton>
    </div>
  </div>
</template>
