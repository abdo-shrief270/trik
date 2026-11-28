<script setup lang="ts">
import type { components } from '~/types/api'
import { change, duration, pct, type Metric } from '../utils/results'

type Results = components['schemas']['PilotResults']

// Before vs after Trik for this seller: what the pilot promises, from the orders' own statuses.
const { t, locale } = useI18n()
const toast = useToast()
const session = useSessionStore()
const { can } = usePermissions()
useHead({ title: () => `${t('results.title')} · ${t('brand.name')}` })

const { data, status, refresh } = useAsyncData(
  () => `results:${session.workspaceId}`,
  async () => (await call(useApi().GET('/analytics/pilot', { params: inWorkspace() }))).data ?? null,
  { watch: [() => session.workspaceId] },
)

const canManage = computed(() => can('analytics.reports.manage'))
const dateFmt = (iso?: string | null, withTime = false) => (iso ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(new Date(iso)) : '')
const dayFmt = (iso?: string) => (iso ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'short' }).format(new Date(`${iso}T12:00:00`)) : '')
const num = (n?: number | null) => new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB').format(n ?? 0)

const METRICS: { id: Metric; of: (c: Record<string, number>) => [number, number] }[] = [
  { id: 'refusal', of: (c) => [c.refused ?? 0, (c.delivered ?? 0) + (c.refused ?? 0)] },
  { id: 'confirmation', of: (c) => [c.confirmed ?? 0, (c.confirmed ?? 0) + (c.cancelled ?? 0)] },
  { id: 'cancellation', of: (c) => [c.cancelled ?? 0, (c.confirmed ?? 0) + (c.cancelled ?? 0)] },
]
const counts = (p: Results['baseline']) => (p?.counts ?? {}) as Record<string, number>
const rate = (p: Results['baseline'], m: Metric) => (p?.rates as Record<Metric, number | null> | undefined)?.[m] ?? null
const ROWS = ['placed', 'confirmed', 'cancelled', 'shipped', 'delivered', 'refused', 'open'] as const
const units = computed(() => ({ h: t('results.units.h'), min: t('results.units.min') }))
const recent = computed(() => [...(data.value?.daily ?? [])].reverse().slice(0, 14))

// go-live
const editing = ref(false)
const liveDate = ref('')
const baselineDays = ref(90)
const saving = ref(false)
function startEdit() {
  liveDate.value = data.value?.live_at ? new Date(data.value.live_at).toISOString().slice(0, 10) : new Date().toISOString().slice(0, 10)
  baselineDays.value = data.value?.baseline_days ?? 90
  editing.value = true
}
async function saveLive(useFirst = false) {
  saving.value = true
  try {
    const res = await call(useApi().PUT('/analytics/pilot', {
      params: inWorkspace(),
      body: { live_at: useFirst ? null : new Date(`${liveDate.value}T00:00:00`).toISOString(), baseline_days: baselineDays.value },
    }))
    if (res.data) data.value = res.data
    editing.value = false
    toast.success(t('results.saved'))
  } catch (e) {
    toast.error(e instanceof ApiError ? t(problemMessageKey(e.problem)) : t('errors.generic'))
  } finally {
    saving.value = false
  }
}

// weekly spreadsheet
const downloading = ref(false)
async function downloadWeekly() {
  downloading.value = true
  try {
    // call() hands back the body itself: with parseAs 'blob', the file
    const file = await call(useApi().GET('/analytics/pilot/weekly.csv', { params: inWorkspace(), parseAs: 'blob' }))
    const url = URL.createObjectURL(file)
    const a = Object.assign(document.createElement('a'), { href: url, download: `trik-results-${new Date().toISOString().slice(0, 10)}.csv` })
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    toast.error(e instanceof ApiError ? t(problemMessageKey(e.problem)) : t('errors.generic'))
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-5xl px-8 py-10">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="font-display text-4xl font-bold tracking-[-0.03em]">{{ t('results.title') }}</h1>
        <p class="mt-2 text-muted">{{ t('results.sub') }}</p>
      </div>
      <UiButton variant="secondary" :loading="downloading" @click="downloadWeekly"><UiIcon name="chevronDown" :size="16" />{{ t('results.download') }}</UiButton>
    </header>

    <UiSkeleton v-if="status === 'pending' && !data" class="mt-8 h-64 w-full" />
    <p v-else-if="status === 'error'" class="mt-8 text-danger">{{ t('errors.generic') }} <button type="button" class="font-semibold underline" @click="refresh()">{{ t('inbox.tryAgain') }}</button></p>

    <template v-else-if="data">
      <!-- go-live -->
      <section class="mt-8 rounded-2xl border border-line bg-white p-6" :aria-label="t('results.liveTitle')">
        <div v-if="!editing" class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-[15px]">
            <template v-if="data.live_at">
              {{ t('results.liveOn', { date: dateFmt(data.live_at, true) }) }}
              <span class="text-muted">· {{ data.live_at_source === 'set' ? t('results.liveSet') : t('results.liveFirst') }} · {{ t('results.baselineDays', { n: data.baseline_days }) }}</span>
            </template>
            <template v-else>{{ t('results.notLive') }}</template>
          </p>
          <UiButton v-if="canManage" size="sm" variant="ghost" @click="startEdit">{{ t('results.changeLive') }}</UiButton>
        </div>
        <form v-else class="flex flex-wrap items-end gap-3" @submit.prevent="saveLive()">
          <label class="text-sm">
            <span class="mb-1 block font-semibold">{{ t('results.liveDate') }}</span>
            <input v-model="liveDate" type="date" required :max="new Date().toISOString().slice(0, 10)" class="rounded-lg border border-line px-3 py-2 text-[15px]">
          </label>
          <label class="text-sm">
            <span class="mb-1 block font-semibold">{{ t('results.baselineLabel') }}</span>
            <select v-model.number="baselineDays" class="rounded-lg border border-line px-3 py-2 text-[15px]">
              <option v-for="d in [30, 60, 90, 120, 180]" :key="d" :value="d">{{ t('results.days', { n: d }) }}</option>
            </select>
          </label>
          <UiButton type="submit" size="sm" :loading="saving">{{ t('results.save') }}</UiButton>
          <UiButton size="sm" variant="ghost" :disabled="saving" @click="saveLive(true)">{{ t('results.useFirst') }}</UiButton>
          <UiButton size="sm" variant="ghost" @click="editing = false">{{ t('orders.discard') }}</UiButton>
        </form>
      </section>

      <UiEmptyState v-if="!data.baseline || !data.after" icon="checkCircle" :title="t('results.empty.title')" :body="t('results.empty.body')" class="mt-6" />

      <template v-else>
        <!-- the promise -->
        <section class="mt-6 grid gap-4 md:grid-cols-3" :aria-label="t('results.promise')">
          <article v-for="m in METRICS" :key="m.id" class="rounded-2xl border border-line bg-white p-6" :class="{ 'md:col-span-1 ring-2 ring-indigo/20': m.id === 'refusal' }">
            <h2 class="text-sm font-semibold text-muted">{{ t(`results.metric.${m.id}`) }}</h2>
            <div class="mt-3 flex items-baseline gap-3">
              <span class="text-lg text-muted tabular-nums line-through decoration-1" :aria-label="t('results.before')">{{ pct(rate(data.baseline, m.id), locale) }}</span>
              <span aria-hidden="true" class="text-muted rtl:rotate-180">→</span>
              <span class="text-4xl font-bold tabular-nums" :aria-label="t('results.after')">{{ pct(rate(data.after, m.id), locale) }}</span>
            </div>
            <p class="mt-2">
              <UiBadge :tone="change(data.change?.[m.id], m.id, locale).tone">{{ t('results.points', { n: change(data.change?.[m.id], m.id, locale).text }) }}</UiBadge>
            </p>
            <p class="mt-3 text-sm text-muted">
              {{ t(`results.of.${m.id}`, { n: num(m.of(counts(data.after))[0]), total: num(m.of(counts(data.after))[1]) }) }}
              <span class="block">{{ t('results.wasBefore', { n: num(m.of(counts(data.baseline))[0]), total: num(m.of(counts(data.baseline))[1]) }) }}</span>
            </p>
          </article>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1.3fr_1fr]">
          <!-- counts -->
          <section class="rounded-2xl border border-line bg-white" :aria-label="t('results.counts')">
            <h2 class="px-6 pt-5 text-lg font-bold">{{ t('results.counts') }}</h2>
            <table class="mt-3 w-full text-[15px]">
              <thead>
                <tr class="border-y border-line text-[13px] text-muted">
                  <th scope="col" class="px-6 py-2.5 text-start font-medium"><span class="sr-only">{{ t('results.status') }}</span></th>
                  <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ t('results.before') }}<span class="block font-normal">{{ dateFmt(data.baseline.from) }} – {{ dateFmt(data.baseline.to) }}</span></th>
                  <th scope="col" class="px-6 py-2.5 text-end font-medium">{{ t('results.after') }}<span class="block font-normal">{{ t('results.since', { date: dateFmt(data.after.from) }) }}</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in ROWS" :key="r" class="border-b border-line last:border-0">
                  <th scope="row" class="px-6 py-2.5 text-start font-normal">{{ t(`results.row.${r}`) }}</th>
                  <td class="px-3 py-2.5 text-end tabular-nums">{{ num(counts(data.baseline)[r]) }}</td>
                  <td class="px-6 py-2.5 text-end font-semibold tabular-nums">{{ num(counts(data.after)[r]) }}</td>
                </tr>
                <tr>
                  <th scope="row" class="px-6 py-2.5 text-start font-normal">{{ t('results.row.timeToConfirm') }}</th>
                  <td class="px-3 py-2.5 text-end text-muted">{{ duration(counts(data.baseline).median_confirm_minutes, units, locale) }}</td>
                  <td class="px-6 py-2.5 text-end font-semibold">{{ duration(counts(data.after).median_confirm_minutes, units, locale) }}</td>
                </tr>
              </tbody>
            </table>
          </section>

          <!-- recent days -->
          <section class="rounded-2xl border border-line bg-white" :aria-label="t('results.recent')">
            <h2 class="px-6 pt-5 text-lg font-bold">{{ t('results.recent') }}</h2>
            <p v-if="!recent.length" class="px-6 pt-2 pb-6 text-muted">{{ t('results.noRecent') }}</p>
            <table v-else class="mt-3 w-full text-[15px]">
              <thead>
                <tr class="border-y border-line text-[13px] text-muted">
                  <th scope="col" class="px-6 py-2.5 text-start font-medium">{{ t('results.day') }}</th>
                  <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ t('results.row.placed') }}</th>
                  <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ t('results.row.confirmed') }}</th>
                  <th scope="col" class="px-6 py-2.5 text-end font-medium">{{ t('results.metric.refusal') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in recent" :key="d.date" class="border-b border-line last:border-0">
                  <th scope="row" class="px-6 py-2 text-start font-normal whitespace-nowrap">{{ dayFmt(d.date) }}</th>
                  <td class="px-3 py-2 text-end tabular-nums">{{ num(d.placed) }}</td>
                  <td class="px-3 py-2 text-end tabular-nums">{{ num(d.confirmed) }}</td>
                  <td class="px-6 py-2 text-end tabular-nums">{{ pct(d.rates?.refusal ?? null, locale) }}</td>
                </tr>
              </tbody>
            </table>
          </section>
        </div>

        <p class="mt-6 text-sm text-muted">{{ t('results.note') }}</p>
      </template>
    </template>
  </div>
</template>
