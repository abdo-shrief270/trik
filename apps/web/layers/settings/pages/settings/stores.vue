<script setup lang="ts">
import type { components } from '~/types/api'
import { STORE_SETTINGS, backfillView, platformName, returnNotice, type Platform } from '../../utils/stores'

type Store = components['schemas']['Store']

// Connected stores: connect Shopify or EasyOrders, follow the first import, choose what Trik writes back.
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const session = useSessionStore()
const { can } = usePermissions()
useHead({ title: () => `${t('settingsNav.stores')} · ${t('brand.name')}` })

const canManage = computed(() => can('commerce.stores.manage'))
const unavailable = ref(false)

const { data: stores, status, refresh } = useAsyncData(
  () => `stores:${session.workspaceId}`,
  async () => {
    try {
      unavailable.value = false
      return (await call(useApi().GET('/commerce/stores', { params: inWorkspace() }))).data ?? []
    } catch (e) {
      if (e instanceof ApiError && e.problem.status === 404) {
        unavailable.value = true // not rolled out for this workspace yet
        return []
      }
      throw e
    }
  },
  { default: () => [] as Store[], watch: [() => session.workspaceId] },
)

// keep the import progress fresh while one runs
const busy = computed(() => stores.value.some((s) => backfillView(s.backfill).busy))
let timer: ReturnType<typeof setInterval> | undefined
watch(busy, (b) => {
  clearInterval(timer)
  if (b) timer = setInterval(() => refresh(), 8000)
}, { immediate: true })
onBeforeUnmount(() => clearInterval(timer))

// back from Shopify or EasyOrders
const arriving = ref<string | null>(null)
onMounted(() => {
  const notice = returnNotice(route.query)
  if (notice?.kind === 'success') toast.success(t(notice.key))
  if (notice?.kind === 'error') toast.error(t(notice.key))
  if (notice?.kind === 'connect') arriving.value = notice.shop
  if (notice) router.replace({ query: {} })
})

// connect
const shop = ref('')
const shopError = ref('')
const connecting = ref<Platform | null>(null)
async function connectShopify(domain?: string) {
  shopError.value = ''
  connecting.value = 'shopify'
  try {
    const res = await call(useApi().POST('/commerce/shopify/install', { params: inWorkspace(), body: { shop: domain ?? shop.value } }))
    if (res.data?.authorize_url) window.location.assign(res.data.authorize_url)
  } catch (e) {
    if (e instanceof ApiError && e.problem.status === 422) shopError.value = t('stores.shopInvalid')
    else toast.error(e instanceof ApiError ? t(`stores.errors.${e.code}`, t(problemMessageKey(e.problem))) : t('errors.generic'))
    connecting.value = null
  }
}
async function connectEasyOrders() {
  connecting.value = 'easyorders'
  try {
    const res = await call(useApi().POST('/commerce/easyorders/install', { params: inWorkspace() }))
    if (res.data?.install_url) window.location.assign(res.data.install_url)
  } catch (e) {
    toast.error(e instanceof ApiError ? t(problemMessageKey(e.problem)) : t('errors.generic'))
    connecting.value = null
  }
}

// a store
const saving = ref<string | null>(null)
async function setSetting(store: Store, key: string, value: boolean) {
  saving.value = `${store.id}:${key}`
  try {
    const res = await call(useApi().PATCH('/commerce/stores/{store}', { params: { ...inWorkspace(), path: { store: store.id } }, body: { settings: { [key]: value } } }))
    if (res.data && stores.value) stores.value = stores.value.map((s) => (s.id === store.id ? res.data! : s))
    toast.success(t('stores.saved'))
  } catch (e) {
    toast.error(e instanceof ApiError ? t(problemMessageKey(e.problem)) : t('errors.generic'))
  } finally {
    saving.value = null
  }
}
async function backfill(store: Store) {
  try {
    await call(useApi().POST('/commerce/stores/{store}/backfill', { params: { header: { ...inWorkspace().header, 'Idempotency-Key': crypto.randomUUID() }, path: { store: store.id } } }))
    toast.success(t('stores.backfillStarted'))
    await refresh()
  } catch (e) {
    toast.error(e instanceof ApiError ? t(`stores.errors.${e.code}`, t(problemMessageKey(e.problem))) : t('errors.generic'))
  }
}
const disconnecting = ref<Store | null>(null)
const confirmOpen = computed({ get: () => !!disconnecting.value, set: (v) => !v && (disconnecting.value = null) })
async function disconnect() {
  const store = disconnecting.value
  if (!store) return
  disconnecting.value = null
  try {
    await call(useApi().DELETE('/commerce/stores/{store}', { params: { ...inWorkspace(), path: { store: store.id } } }))
    toast.success(t('stores.disconnected', { name: nameOf(store) }))
    await refresh()
  } catch (e) {
    toast.error(e instanceof ApiError ? t(problemMessageKey(e.problem)) : t('errors.generic'))
  }
}

const statusTone = (s: string) => (s === 'active' ? 'success' : s === 'uninstalled' ? 'warning' : 'neutral')
const when = (iso?: string | null) => (iso ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '')
const day = (iso?: string | null) => (iso ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso)) : '')
const settingsOf = (s: Store) => STORE_SETTINGS[s.platform as Platform] ?? []
// Shopify stores have a name and a domain; an EasyOrders store may only have an id
const nameOf = (s: Store) => s.name || (s.platform === 'shopify' ? s.shop_domain : t('stores.unnamed', { platform: platformName(s.platform) }))
const settingValue = (s: Store, key: string) => (s.settings as Record<string, boolean | undefined>)[key] ?? false
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-8 py-10">
    <h1 class="mb-6 font-display text-4xl font-bold tracking-[-0.03em]">{{ t('nav.settings') }}</h1>
    <SettingsNav />

    <UiEmptyState v-if="unavailable" icon="store" :title="t('stores.unavailable.title')" :body="t('stores.unavailable.body')" />

    <template v-else>
      <!-- arrived from Shopify's install link -->
      <section v-if="arriving && canManage" class="mb-6 rounded-2xl border-2 border-indigo/30 bg-indigo/5 p-6" aria-live="polite">
        <h2 class="text-lg font-bold">{{ t('stores.arriving.title', { shop: arriving }) }}</h2>
        <p class="mt-1 text-[15px] text-muted">{{ t('stores.arriving.body', { workspace: session.workspace?.name ?? '' }) }}</p>
        <div class="mt-4 flex gap-2">
          <UiButton :loading="connecting === 'shopify'" @click="connectShopify(arriving)">{{ t('stores.arriving.connect') }}</UiButton>
          <UiButton variant="ghost" @click="arriving = null">{{ t('stores.arriving.notNow') }}</UiButton>
        </div>
      </section>

      <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="text-2xl font-bold">{{ t('stores.title') }}</h2>
          <p class="mt-1 text-[15px] text-muted">{{ t('stores.sub') }}</p>
        </div>
      </header>

      <UiSkeleton v-if="status === 'pending' && !stores.length" class="mt-6 h-40 w-full" />

      <ul v-else-if="stores.length" class="mt-6 space-y-4">
        <li v-for="s in stores" :key="s.id" class="rounded-2xl border border-line bg-white p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-muted">{{ platformName(s.platform) }}</p>
              <h3 class="truncate text-lg font-bold"><bdi>{{ nameOf(s) }}</bdi></h3>
              <p v-if="s.platform === 'shopify' || !s.name" class="truncate text-sm text-muted" dir="ltr">{{ s.shop_domain }}</p>
            </div>
            <UiBadge :tone="statusTone(s.status)">{{ t(`stores.status.${s.status}`) }}</UiBadge>
          </div>

          <p v-if="s.status === 'uninstalled'" class="mt-3 rounded-lg bg-warning-bg px-3 py-2 text-sm text-warning">{{ t('stores.uninstalledHint') }}</p>

          <dl class="mt-4 grid gap-3 text-[15px] sm:grid-cols-2">
            <div>
              <dt class="text-sm text-muted">{{ t('stores.import') }}</dt>
              <dd class="mt-0.5 flex items-center gap-2">
                <UiSpinner v-if="backfillView(s.backfill).busy" :size="14" />
                <span :class="{ 'text-danger': backfillView(s.backfill).tone === 'danger' }">
                  {{ t(`stores.backfill.${backfillView(s.backfill).key}`, { ...backfillView(s.backfill).params, since: day(s.backfill.since) }) }}
                </span>
              </dd>
              <dd v-if="s.backfill.status === 'failed' && s.backfill.error" class="mt-0.5 text-sm text-muted">{{ s.backfill.error }}</dd>
            </div>
            <div>
              <dt class="text-sm text-muted">{{ t('stores.lastHeard') }}</dt>
              <dd class="mt-0.5">{{ s.last_webhook_at ? when(s.last_webhook_at) : t('stores.notYet') }}</dd>
            </div>
          </dl>

          <fieldset v-if="s.status === 'active'" class="mt-5 border-t border-line pt-4">
            <legend class="sr-only">{{ t('stores.writeBack') }}</legend>
            <p class="text-sm font-semibold text-muted">{{ t('stores.writeBack') }}</p>
            <div v-for="key in settingsOf(s)" :key="key" class="mt-3 flex items-start justify-between gap-4">
              <div>
                <p class="text-[15px] font-semibold">{{ t(`stores.settings.${s.platform}.${key}.label`) }}</p>
                <p class="text-sm text-muted">{{ t(`stores.settings.${s.platform}.${key}.hint`) }}</p>
              </div>
              <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                <input
                  type="checkbox" role="switch" class="peer sr-only"
                  :checked="settingValue(s, key)" :disabled="!canManage || saving === `${s.id}:${key}`"
                  :aria-label="t(`stores.settings.${s.platform}.${key}.label`)"
                  @change="setSetting(s, key, ($event.target as HTMLInputElement).checked)"
                >
                <span class="h-7 w-12 rounded-full bg-line transition-colors peer-checked:bg-indigo peer-focus-visible:ring-4 peer-focus-visible:ring-indigo/30 peer-disabled:opacity-50" />
                <span class="absolute start-1 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5" />
              </label>
            </div>
          </fieldset>

          <div v-if="canManage" class="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
            <UiButton v-if="s.status === 'active' && s.platform === 'shopify' && !backfillView(s.backfill).busy" size="sm" variant="secondary" @click="backfill(s)">{{ t('stores.reimport') }}</UiButton>
            <UiButton v-if="s.status !== 'active' && s.platform === 'shopify'" size="sm" variant="secondary" @click="connectShopify(s.shop_domain)">{{ t('stores.reconnect') }}</UiButton>
            <UiButton v-if="s.status === 'active'" size="sm" variant="ghost" class="!text-danger" @click="disconnecting = s">{{ t('stores.disconnect') }}</UiButton>
          </div>
        </li>
      </ul>

      <UiEmptyState v-else icon="store" :title="t('stores.empty.title')" :body="t('stores.empty.body')" class="mt-6" />

      <!-- connect -->
      <section v-if="canManage" class="mt-8 grid gap-4 sm:grid-cols-2" :aria-label="t('stores.connect')">
        <form class="rounded-2xl border border-line bg-white p-6" novalidate @submit.prevent="connectShopify()">
          <h3 class="text-lg font-bold">Shopify</h3>
          <p class="mt-1 text-sm text-muted">{{ t('stores.shopifyHint') }}</p>
          <div class="mt-4"><UiInput v-model="shop" dir="ltr" :label="t('stores.shopLabel')" :hint="t('stores.shopHint')" :error="shopError || undefined" autocomplete="off" /></div>
          <UiButton type="submit" class="mt-4 w-full" :loading="connecting === 'shopify'" :disabled="!shop.trim()">{{ t('stores.connectShopify') }}</UiButton>
        </form>
        <div class="flex flex-col rounded-2xl border border-line bg-white p-6">
          <h3 class="text-lg font-bold">EasyOrders</h3>
          <p class="mt-1 flex-1 text-sm text-muted">{{ t('stores.easyordersHint') }}</p>
          <UiButton class="mt-4 w-full" :loading="connecting === 'easyorders'" @click="connectEasyOrders">{{ t('stores.connectEasyOrders') }}</UiButton>
        </div>
      </section>
      <p v-else class="mt-6 text-sm text-muted">{{ t('stores.readOnly') }}</p>
    </template>

    <UiModal v-model="confirmOpen" size="sm" :title="t('stores.disconnectTitle', { name: disconnecting ? nameOf(disconnecting) : '' })">
      <p class="text-[15px] leading-relaxed text-muted">{{ t('stores.disconnectBody') }}</p>
      <template #footer>
        <UiButton variant="secondary" @click="disconnecting = null">{{ t('stores.keep') }}</UiButton>
        <UiButton variant="danger" @click="disconnect">{{ t('stores.disconnect') }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
