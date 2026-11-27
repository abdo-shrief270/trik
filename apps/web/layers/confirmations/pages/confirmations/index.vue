<script setup lang="ts">
import type { BoardOrder } from '../../composables/useBoard'
import { useBoard, useGovernorates } from '../../composables/useBoard'
import { STAGES, activeFilters, eligibleFor, emptyFilters, isStage, type BoardFilters } from '../../utils/board'

// The confirmation board: stages · filters · orders with bulk actions. The stage lives in the URL (?stage=).
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermissions()
const governorates = useGovernorates()
useHead({ title: () => `${t('nav.confirmations')} · ${t('brand.name')}` })

const filters = ref<BoardFilters>(emptyFilters(isStage(route.query.stage) ? route.query.stage : 'new'))
watch(() => filters.value.stage, (stage) => router.replace({ query: { ...route.query, stage } }))
const board = useBoard(filters)

const stageGroups = computed(() => [{
  id: 'stages',
  label: t('board.queue'),
  items: STAGES.map((s) => ({ id: s, label: t(`board.stage.${s}`), count: board.counts.value[s] ?? 0, tone: s === 'needs_call' && (board.counts.value[s] ?? 0) > 0 ? ('danger' as const) : undefined })),
}])
const stage = computed({ get: () => filters.value.stage, set: (s: string) => isStage(s) && (filters.value = { ...filters.value, stage: s }) })
const filterCount = computed(() => activeFilters(filters.value))
function clearFilters() {
  filters.value = emptyFilters(filters.value.stage)
}

const canConfirm = computed(() => can('orders.orders.confirm'))
const busyRow = ref<string | null>(null)
const busyBulk = ref<'confirm' | 'cancel' | 'remind' | null>(null)
const drawer = ref<BoardOrder | null>(null)
const drawerBusy = ref<'confirm' | 'cancel' | null>(null)

function explain(e: unknown) {
  if (!(e instanceof ApiError)) return t('errors.generic')
  const k = `inbox.errors.${e.code}`
  return t(k) !== k ? t(k) : t(problemMessageKey(e.problem))
}

async function confirmOne(o: BoardOrder, fromDrawer = false) {
  if (fromDrawer) drawerBusy.value = 'confirm'
  else busyRow.value = o.id
  try {
    await board.single(o, 'confirm')
    toast.success(t('order.confirmedDone', { n: o.number }))
    if (fromDrawer) drawer.value = null
  } catch (e) {
    toast.error(explain(e))
  } finally {
    busyRow.value = null
    drawerBusy.value = null
  }
}

async function runBulk(action: 'confirm' | 'cancel' | 'remind', reason?: string) {
  busyBulk.value = action
  try {
    const s = await board.bulk(action, reason)
    if (!s) return
    const lines = [t(`board.bulk.done.${action}`, { n: s.done })]
    if (s.skipped) lines.push(t('board.bulk.skipped', { n: s.skipped }))
    for (const [code, n] of Object.entries(s.errors)) lines.push(t('board.bulk.failed', { n, reason: t(`inbox.errors.${code}`, code) }))
    if (s.notFound) lines.push(t('board.bulk.gone', { n: s.notFound }))
    if (Object.keys(s.errors).length) toast.error(lines.join(' · '))
    else toast.success(lines.join(' · '))
  } catch (e) {
    toast.error(explain(e))
  } finally {
    busyBulk.value = null
  }
}

// Cancel (one or many) asks first, with an optional reason.
const cancelTarget = ref<'bulk' | BoardOrder | null>(null)
const cancelReason = ref('')
const cancelOpen = computed({ get: () => !!cancelTarget.value, set: (v) => { if (!v) cancelTarget.value = null } })
async function doCancel() {
  const target = cancelTarget.value
  const reason = cancelReason.value.trim() || undefined
  cancelTarget.value = null
  cancelReason.value = ''
  if (target === 'bulk') return runBulk('cancel', reason)
  if (!target) return
  drawerBusy.value = 'cancel'
  try {
    await board.single(target, 'cancel', reason)
    toast.success(t('order.cancelledDone', { n: target.number }))
    drawer.value = null
  } catch (e) {
    toast.error(explain(e))
  } finally {
    drawerBusy.value = null
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-1">
    <AppFilterPanel v-model="stage" :title="t('nav.confirmations')" :groups="stageGroups" class="hidden lg:flex">
      <section class="mt-6 space-y-4 border-t border-line px-2 pt-5" :aria-label="t('board.filters')">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold text-muted">{{ t('board.filters') }}</h3>
          <button v-if="filterCount" type="button" class="text-sm font-semibold text-indigo" @click="clearFilters">{{ t('board.clear', { n: filterCount }) }}</button>
        </div>
        <UiSelect v-model="filters.owner" :label="t('board.owner')" :options="[{ value: '', label: t('board.ownerAll') }, { value: 'me', label: t('board.ownerMe') }, { value: 'unassigned', label: t('board.ownerNone') }]" />
        <UiSelect v-model="filters.governorate" :label="t('board.governorate')" :options="[{ value: '', label: t('board.anyGovernorate') }, ...governorates.options.value]" />
        <fieldset>
          <legend class="text-sm font-semibold">{{ t('board.minRisk') }}</legend>
          <div class="mt-1.5 grid grid-cols-3 gap-1.5">
            <label v-for="r in [0, 40, 60] as const" :key="r" class="flex h-9 cursor-pointer items-center justify-center rounded-lg border text-sm font-semibold has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-indigo" :class="filters.minRisk === r ? 'border-indigo bg-indigo text-white' : 'border-line hover:bg-paper'">
              <input v-model="filters.minRisk" type="radio" name="min-risk" :value="r" class="sr-only">{{ r ? `${r}+` : t('board.any') }}
            </label>
          </div>
        </fieldset>
        <div class="grid grid-cols-2 gap-2">
          <UiInput v-model="filters.minTotal" :label="t('board.minTotal')" dir="ltr" />
          <UiInput v-model="filters.maxTotal" :label="t('board.maxTotal')" dir="ltr" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <label class="flex flex-col gap-1.5 text-sm font-semibold">{{ t('board.from') }}<input v-model="filters.from" type="date" class="h-11 rounded-lg border border-line px-2 font-normal" dir="ltr"></label>
          <label class="flex flex-col gap-1.5 text-sm font-semibold">{{ t('board.to') }}<input v-model="filters.to" type="date" class="h-11 rounded-lg border border-line px-2 font-normal" dir="ltr"></label>
        </div>
      </section>
    </AppFilterPanel>

    <section class="flex min-w-0 flex-1 flex-col bg-paper" :aria-label="t(`board.stage.${filters.stage}`)">
      <header class="flex flex-wrap items-center gap-3 px-6 pt-6 pb-4">
        <h1 class="font-display text-3xl font-bold tracking-[-0.02em]">{{ t(`board.stage.${filters.stage}`) }}</h1>
        <UiBadge :count="board.counts.value[filters.stage] ?? 0" />
        <UiSelect v-model="stage" class="w-48 lg:hidden" :label="t('board.queue')" :options="STAGES.map((s) => ({ value: s, label: `${t(`board.stage.${s}`)} (${board.counts.value[s] ?? 0})` }))" />
        <span class="flex-1" />
        <UiButton to="/confirmations/settings" variant="secondary" size="sm"><UiIcon name="rules" :size="16" />{{ t('board.rules') }}</UiButton>
      </header>

      <!-- Bulk actions -->
      <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 -translate-y-2">
        <div v-if="board.selected.value.size && canConfirm" class="mx-6 mb-3 flex flex-wrap items-center gap-2 rounded-2xl border border-indigo/20 bg-indigo-soft px-4 py-3" role="region" :aria-label="t('board.bulk.label')">
          <span class="me-2 font-semibold text-indigo-strong">{{ t('board.bulk.selected', { n: board.selected.value.size }) }}</span>
          <UiButton v-if="eligibleFor('confirm', filters.stage)" size="sm" :loading="busyBulk === 'confirm'" :disabled="!!busyBulk" @click="runBulk('confirm')"><UiIcon name="check" :size="16" />{{ t('board.confirm') }}</UiButton>
          <UiButton v-if="eligibleFor('remind', filters.stage)" size="sm" variant="secondary" :loading="busyBulk === 'remind'" :disabled="!!busyBulk" @click="runBulk('remind')">{{ t('board.bulk.remind') }}</UiButton>
          <UiButton v-if="eligibleFor('cancel', filters.stage)" size="sm" variant="secondary" class="!text-danger" :disabled="!!busyBulk" @click="cancelTarget = 'bulk'">{{ t('board.bulk.cancel') }}</UiButton>
          <span class="flex-1" />
          <button type="button" class="text-sm font-semibold text-muted hover:text-ink" @click="board.selected.value = new Set()">{{ t('board.bulk.clear') }}</button>
        </div>
      </Transition>

      <div class="mx-6 mb-6 flex-1 overflow-hidden rounded-2xl border border-line bg-white">
        <div class="h-full overflow-y-auto">
          <template v-if="board.loading.value && !board.rows.value.length">
            <div v-for="i in 6" :key="i" class="flex items-center gap-4 border-b border-line px-5 py-4"><UiSkeleton class="h-4 w-16" /><UiSkeleton class="h-4 w-40" /><UiSkeleton class="ms-auto h-4 w-24" /></div>
          </template>
          <div v-else-if="board.error.value" class="p-10 text-center">
            <p class="text-danger">{{ explain(board.error.value) }}</p>
            <UiButton variant="secondary" size="sm" class="mt-4" @click="board.reload()">{{ t('inbox.tryAgain') }}</UiButton>
          </div>
          <UiEmptyState v-else-if="!board.rows.value.length" icon="checkCircle" :title="t(`board.empty.${filters.stage}.title`)" :body="filterCount ? t('board.emptyFiltered') : t(`board.empty.${filters.stage}.body`)">
            <UiButton v-if="filterCount" variant="secondary" size="sm" @click="clearFilters">{{ t('board.clear', { n: filterCount }) }}</UiButton>
          </UiEmptyState>
          <BoardTable
            v-else
            :rows="board.rows.value"
            :stage="filters.stage"
            :selected="board.selected.value"
            :all-selected="board.allSelected.value"
            :can-confirm="canConfirm"
            :busy="busyRow"
            :governorate="governorates.nameOf"
            @toggle="board.toggle"
            @toggle-all="board.toggleAll"
            @open="drawer = $event"
            @confirm="confirmOne($event)"
          />
          <div v-if="board.hasMore.value" class="p-4 text-center">
            <UiButton variant="ghost" size="sm" :loading="board.loading.value" @click="board.loadMore()">{{ t('inbox.more') }}</UiButton>
          </div>
        </div>
      </div>
    </section>

    <OrderDrawer :order="drawer" :can-confirm="canConfirm" :busy="drawerBusy" @close="drawer = null" @confirm="confirmOne($event, true)" @cancel="cancelTarget = $event" />

    <UiModal v-model="cancelOpen" size="sm" :title="cancelTarget === 'bulk' ? t('board.bulk.cancelTitle', { n: board.selected.value.size }) : t('order.cancelTitle', { n: (cancelTarget as BoardOrder | null)?.number ?? '' })">
      <p class="text-[15px] leading-relaxed text-muted">{{ t('order.cancelBody') }}</p>
      <div class="mt-4"><UiInput v-model="cancelReason" :label="t('board.reason')" :hint="t('board.reasonHint')" /></div>
      <template #footer>
        <UiButton variant="secondary" @click="cancelTarget = null">{{ t('order.keep') }}</UiButton>
        <UiButton variant="danger" @click="doCancel">{{ t('order.cancelYes') }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
