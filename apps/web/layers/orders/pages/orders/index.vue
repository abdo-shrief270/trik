<script setup lang="ts">
import type { components } from '~/types/api'
import { ORDER_STATUSES, isOrderStatus, itemsSummary } from '../../utils/orders'
import { formatMoney, orderTone } from '../../../inbox/utils/inbox'

type Order = components['schemas']['Order']

// All orders, newest first. Filters live in the URL (?status=, ?fix=1).
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { can } = usePermissions()
const governorates = useGovernorates()
useHead({ title: () => `${t('nav.orders')} · ${t('brand.name')}` })

const status = computed({
  get: () => (isOrderStatus(route.query.status) ? route.query.status : 'all'),
  set: (s: string) => router.replace({ query: { ...route.query, status: s === 'all' ? undefined : s } }),
})
const needsFix = computed({
  get: () => route.query.fix === '1',
  set: (v: boolean) => router.replace({ query: { ...route.query, fix: v ? '1' : undefined } }),
})

const rows = ref<Order[]>([])
const next = ref<string | null>(null)
const loading = ref(false)
const error = ref<ApiError | null>(null)

async function page(cursor?: string) {
  const res = await call(useApi().GET('/orders', {
    params: { ...inWorkspace(), query: { per_page: 50, ...(isOrderStatus(status.value) ? { status: status.value } : {}), ...(needsFix.value ? { needs_address_fix: true } : {}), ...(cursor ? { cursor } : {}) } },
  }))
  return { data: res.data ?? [], next: res.meta?.next_cursor ?? null }
}
async function reload() {
  rows.value = []
  next.value = null
  loading.value = true
  error.value = null
  try {
    const r = await page()
    rows.value = r.data
    next.value = r.next
  } catch (e) {
    error.value = e instanceof ApiError ? e : new ApiError(toProblem(0, null))
  } finally {
    loading.value = false
  }
}
async function loadMore() {
  if (!next.value || loading.value) return
  loading.value = true
  try {
    const r = await page(next.value)
    rows.value = [...rows.value, ...r.data.filter((o) => !rows.value.some((x) => x.id === o.id))]
    next.value = r.next
  } finally {
    loading.value = false
  }
}
watch([status, needsFix, () => session.workspaceId], reload, { immediate: true })

const groups = computed(() => [{
  id: 'status',
  label: t('orders.status'),
  items: [{ id: 'all', label: t('orders.all') }, ...ORDER_STATUSES.map((s) => ({ id: s, label: t(`order.status.${s}`) }))],
}])
const placed = (iso?: string) => (iso ? `${shortTime(iso, locale.value)}` : '')
</script>

<template>
  <div class="flex min-h-0 flex-1">
    <AppFilterPanel v-model="status" :title="t('nav.orders')" :groups="groups" class="hidden lg:flex">
      <label class="mx-2 mt-6 flex cursor-pointer items-center gap-2.5 border-t border-line pt-5 text-[15px]">
        <input v-model="needsFix" type="checkbox" class="size-4 accent-indigo">{{ t('orders.needsFix') }}
      </label>
    </AppFilterPanel>

    <section class="flex min-w-0 flex-1 flex-col bg-paper" :aria-label="t('nav.orders')">
      <header class="flex flex-wrap items-center gap-3 px-6 pt-6 pb-4">
        <h1 class="font-display text-3xl font-bold tracking-[-0.02em]">{{ status === 'all' ? t('orders.all') : t(`order.status.${status}`) }}</h1>
        <UiSelect v-model="status" class="w-48 lg:hidden" :label="t('orders.status')" :options="[{ value: 'all', label: t('orders.all') }, ...ORDER_STATUSES.map((s) => ({ value: s, label: t(`order.status.${s}`) }))]" />
        <span class="flex-1" />
        <UiButton v-if="can('orders.orders.create')" to="/orders/new" size="sm"><UiIcon name="plus" :size="16" />{{ t('orders.new') }}</UiButton>
      </header>

      <div class="mx-6 mb-6 flex-1 overflow-hidden rounded-2xl border border-line bg-white">
        <div class="h-full overflow-y-auto">
          <template v-if="loading && !rows.length">
            <div v-for="i in 6" :key="i" class="flex items-center gap-4 border-b border-line px-5 py-4"><UiSkeleton class="h-4 w-16" /><UiSkeleton class="h-4 w-48" /><UiSkeleton class="ms-auto h-4 w-24" /></div>
          </template>
          <div v-else-if="error" class="p-10 text-center">
            <p class="text-danger">{{ t(problemMessageKey(error.problem)) }}</p>
            <UiButton variant="secondary" size="sm" class="mt-4" @click="reload">{{ t('inbox.tryAgain') }}</UiButton>
          </div>
          <UiEmptyState v-else-if="!rows.length" icon="orders" :title="t('orders.empty.title')" :body="needsFix || status !== 'all' ? t('orders.empty.filtered') : t('orders.empty.body')">
            <UiButton v-if="can('orders.orders.create') && status === 'all' && !needsFix" to="/orders/new" size="sm"><UiIcon name="plus" :size="16" />{{ t('orders.new') }}</UiButton>
          </UiEmptyState>
          <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-[15px]">
              <thead>
                <tr class="border-b border-line text-[13px] text-muted">
                  <th scope="col" class="px-5 py-3 text-start font-medium">{{ t('board.col.order') }}</th>
                  <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('board.col.customer') }}</th>
                  <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('orders.items') }}</th>
                  <th scope="col" class="px-3 py-3 text-end font-medium">{{ t('board.col.amount') }}</th>
                  <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('orders.statusCol') }}</th>
                  <th scope="col" class="px-5 py-3 text-start font-medium">{{ t('orders.placed') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="o in rows" :key="o.id" class="border-b border-line hover:bg-paper/70">
                  <td class="px-5 py-3">
                    <NuxtLink :to="`/orders/${o.id}`" class="font-semibold text-indigo hover:underline" dir="ltr">#{{ o.number }}</NuxtLink>
                    <div class="text-[13px] text-muted">{{ t(`board.source.${o.source ?? 'chat'}`) }}</div>
                  </td>
                  <td class="px-3 py-3">
                    <div class="font-medium" dir="auto">{{ o.customer?.name || formatPhone(o.customer?.phone) }}</div>
                    <div class="text-[13px] text-muted" dir="auto">{{ governorates.nameOf(o.address?.governorate) ?? o.address?.area ?? '' }}</div>
                  </td>
                  <td class="max-w-[16rem] px-3 py-3">
                    <div class="truncate" dir="auto">{{ itemsSummary(o.items).titles.join('، ') }}<template v-if="itemsSummary(o.items).more"> +{{ itemsSummary(o.items).more }}</template></div>
                    <div class="text-[13px] text-muted">{{ t('orders.pieces', { n: itemsSummary(o.items).count }) }}</div>
                  </td>
                  <td class="px-3 py-3 text-end font-medium tabular-nums" dir="ltr">{{ formatMoney(o.total, locale) }}</td>
                  <td class="px-3 py-3">
                    <div class="flex flex-wrap items-center gap-1.5">
                      <UiBadge :tone="orderTone(o.status)"><BrandMark v-if="o.status === 'confirmed'" tone="ink" :size="18" />{{ t(`order.status.${o.status}`) }}</UiBadge>
                      <UiBadge v-if="o.address?.needs_fix" tone="warning"><UiIcon name="alert" :size="13" />{{ t('orders.fixAddress') }}</UiBadge>
                    </div>
                  </td>
                  <td class="px-5 py-3 text-muted">{{ placed(o.placed_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="next" class="p-4 text-center">
            <UiButton variant="ghost" size="sm" :loading="loading" @click="loadMore">{{ t('inbox.more') }}</UiButton>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
