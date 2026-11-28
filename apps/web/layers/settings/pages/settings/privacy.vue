<script setup lang="ts">
import type { components } from '~/types/api'

type PrivacyRequest = components['schemas']['PrivacyRequest']

// Privacy requests from store platforms (Shopify's data requests and erasures), and the data exports to hand over.
const { t, locale } = useI18n()
const toast = useToast()
const session = useSessionStore()
const { can } = usePermissions()
useHead({ title: () => `${t('settingsNav.privacy')} · ${t('brand.name')}` })

const { data: requests, status, error } = useAsyncData(
  () => `privacy:${session.workspaceId}`,
  async () => (await call(useApi().GET('/compliance/requests', { params: inWorkspace() }))).data ?? [],
  { default: () => [] as PrivacyRequest[], watch: [() => session.workspaceId] },
)

const when = (iso?: string | null) => (iso ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '')
const tone = (s: string) => (s === 'done' ? 'success' : s === 'failed' ? 'danger' : 'indigo')
const counts = (r: PrivacyRequest) => Object.entries(r.result as Record<string, number>).filter(([, n]) => n > 0).map(([k, n]) => t(`privacy.count.${k}`, { n }, n))

const downloading = ref<string | null>(null)
async function download(r: PrivacyRequest) {
  downloading.value = r.id
  try {
    const res = await call(useApi().GET('/compliance/requests/{privacyRequest}/export', { params: { ...inWorkspace(), path: { privacyRequest: r.id } } }))
    const blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = Object.assign(document.createElement('a'), { href: url, download: `trik-data-request-${r.id}.json` })
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    toast.error(e instanceof ApiError ? t(e.code === 'export-not-available' ? 'privacy.expired' : problemMessageKey(e.problem)) : t('errors.generic'))
  } finally {
    downloading.value = null
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-8 py-10">
    <h1 class="mb-6 font-display text-4xl font-bold tracking-[-0.03em]">{{ t('nav.settings') }}</h1>
    <SettingsNav />

    <header>
      <h2 class="text-2xl font-bold">{{ t('privacy.title') }}</h2>
      <p class="mt-1 text-[15px] text-muted">{{ t('privacy.sub') }}</p>
    </header>

    <UiSkeleton v-if="status === 'pending' && !requests.length" class="mt-6 h-32 w-full" />
    <p v-else-if="error" class="mt-6 text-danger">{{ t(problemMessageKey((error as unknown as ApiError).problem ?? { status: 0, code: 'network' })) }}</p>
    <UiEmptyState v-else-if="!requests.length" icon="info" :title="t('privacy.empty.title')" :body="t('privacy.empty.body')" class="mt-6" />

    <ul v-else class="mt-6 space-y-3">
      <li v-for="r in requests" :key="r.id" class="rounded-2xl border border-line bg-white p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="font-semibold">{{ t(`privacy.kind.${r.kind}`) }}</p>
            <p class="text-sm text-muted">{{ t('privacy.from', { origin: r.origin === 'shopify' ? 'Shopify' : r.origin }) }}<template v-if="r.origin_ref"> · <span dir="ltr">{{ r.origin_ref }}</span></template> · {{ when(r.created_at) }}</p>
          </div>
          <UiBadge :tone="tone(r.status)">{{ t(`privacy.status.${r.status}`) }}</UiBadge>
        </div>
        <p v-if="counts(r).length" class="mt-2 text-sm text-muted">{{ counts(r).join(' · ') }}</p>
        <div v-if="r.kind === 'data_request' && r.status === 'done'" class="mt-3 flex flex-wrap items-center gap-3">
          <UiButton v-if="r.export_available && can('compliance.requests.export')" size="sm" variant="secondary" :loading="downloading === r.id" @click="download(r)">{{ t('privacy.download') }}</UiButton>
          <span class="text-sm text-muted">{{ r.export_available ? t('privacy.until', { date: when(r.export_expires_at) }) : t('privacy.expired') }}</span>
        </div>
      </li>
    </ul>
  </div>
</template>
