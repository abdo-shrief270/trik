<script setup lang="ts">
import { change, pct } from '../utils/results'

/** Home: the pilot's promise at a glance (refusals and confirmations, before → after). Hidden until there is a before and an after. */
const { t, locale } = useI18n()
const session = useSessionStore()
const { can } = usePermissions()

const { data } = useAsyncData(
  () => `results-card:${session.workspaceId}`,
  async () => {
    if (!can('analytics.reports.view')) return null
    try {
      return (await call(useApi().GET('/analytics/pilot', { params: inWorkspace() }))).data ?? null
    } catch {
      return null
    }
  },
  { watch: [() => session.workspaceId, () => can('analytics.reports.view')] },
)

const show = computed(() => !!data.value?.baseline && !!data.value?.after)
const tiles = computed(() => (['refusal', 'confirmation'] as const).map((m) => ({
  id: m,
  before: pct((data.value?.baseline?.rates as Record<string, number | null> | undefined)?.[m] ?? null, locale.value),
  after: pct((data.value?.after?.rates as Record<string, number | null> | undefined)?.[m] ?? null, locale.value),
  change: change(data.value?.change?.[m], m, locale.value),
})))
</script>

<template>
  <section v-if="show" class="rounded-2xl border border-line bg-white p-6" :aria-label="t('results.title')">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-lg font-bold">{{ t('results.cardTitle') }}</h2>
        <p class="text-sm text-muted">{{ t('results.cardSub') }}</p>
      </div>
      <UiButton to="/results" size="sm" variant="secondary">{{ t('results.see') }}<UiIcon name="chevronRight" :size="16" class="rtl:rotate-180" /></UiButton>
    </div>
    <dl class="mt-5 grid gap-4 sm:grid-cols-2">
      <div v-for="tile in tiles" :key="tile.id">
        <dt class="text-sm text-muted">{{ t(`results.metric.${tile.id}`) }}</dt>
        <dd class="mt-1 flex items-baseline gap-2">
          <span class="text-muted tabular-nums line-through decoration-1">{{ tile.before }}</span>
          <span aria-hidden="true" class="text-muted rtl:rotate-180">→</span>
          <span class="text-2xl font-bold tabular-nums">{{ tile.after }}</span>
          <UiBadge :tone="tile.change.tone">{{ t('results.points', { n: tile.change.text }) }}</UiBadge>
        </dd>
      </div>
    </dl>
  </section>
</template>
