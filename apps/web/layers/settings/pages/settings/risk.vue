<script setup lang="ts">
import type { components } from '~/types/api'

type RiskSettings = components['schemas']['RiskSettings']

// The refusal risk threshold and the points each rule adds; saving scores open orders again.
const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const { can } = usePermissions()
useHead({ title: () => `${t('settingsNav.risk')} · ${t('brand.name')}` })

const RULES = ['first_order', 'refused_before', 'cancelled_before', 'unreachable_before', 'delivered_before', 'high_value', 'address_incomplete', 'address_flagged', 'late_night', 'baseline'] as const
type Rule = (typeof RULES)[number]

const canManage = computed(() => can('risk.settings.manage'))
const unavailable = ref(false)
const threshold = ref(70)
const weights = ref<Record<Rule, number>>({} as Record<Rule, number>)
const defaults = ref<RiskSettings['defaults']>({})

const { status } = useAsyncData(
  () => `risk-settings:${session.workspaceId}`,
  async () => {
    try {
      const s = (await call(useApi().GET('/risk/settings', { params: inWorkspace() }))).data
      if (s) apply(s)
      unavailable.value = false
      return s ?? null
    } catch (e) {
      if (e instanceof ApiError && e.problem.status === 404) {
        unavailable.value = true
        return null
      }
      throw e
    }
  },
  { watch: [() => session.workspaceId] },
)

function apply(s: RiskSettings) {
  threshold.value = s.threshold
  defaults.value = s.defaults
  const w = s.weights as Record<string, number | undefined>
  weights.value = Object.fromEntries(RULES.map((r) => [r, w[r] ?? 0])) as Record<Rule, number>
}

const defaultOf = (r: Rule) => (defaults.value.weights as Record<string, number | undefined> | undefined)?.[r] ?? 0
const changed = (r: Rule) => weights.value[r] !== defaultOf(r)
const errors = computed(() => Object.fromEntries(RULES.map((r) => [r, Number.isInteger(weights.value[r]) && weights.value[r] >= -50 && weights.value[r] <= 50 ? '' : t('risk.range')])))
const thresholdError = computed(() => (Number.isInteger(threshold.value) && threshold.value >= 1 && threshold.value <= 100 ? '' : t('risk.thresholdRange')))
const valid = computed(() => !thresholdError.value && RULES.every((r) => !errors.value[r]))

const saving = ref(false)
async function save() {
  if (!valid.value) return
  saving.value = true
  try {
    const res = await call(useApi().PUT('/risk/settings', { params: inWorkspace(), body: { threshold: threshold.value, weights: weights.value } }))
    if (res.data) apply(res.data)
    toast.success(t('risk.saved'))
  } catch (e) {
    toast.error(e instanceof ApiError ? t(problemMessageKey(e.problem)) : t('errors.generic'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-8 py-10">
    <h1 class="mb-6 font-display text-4xl font-bold tracking-[-0.03em]">{{ t('nav.settings') }}</h1>
    <SettingsNav />

    <UiEmptyState v-if="unavailable" icon="alert" :title="t('risk.unavailable.title')" :body="t('risk.unavailable.body')" />
    <UiSkeleton v-else-if="status === 'pending'" class="h-64 w-full" />

    <form v-else class="space-y-6" novalidate @submit.prevent="save">
      <header>
        <h2 class="text-2xl font-bold">{{ t('risk.title') }}</h2>
        <p class="mt-1 text-[15px] text-muted">{{ t('risk.sub') }}</p>
      </header>

      <section class="rounded-2xl border border-line bg-white p-6">
        <label for="risk-threshold" class="text-lg font-bold">{{ t('risk.threshold') }}</label>
        <p class="mt-1 text-sm text-muted">{{ t('risk.thresholdHint') }}</p>
        <div class="mt-4 flex items-center gap-4">
          <input id="risk-threshold" v-model.number="threshold" type="range" min="1" max="100" class="flex-1 accent-indigo" :disabled="!canManage" :aria-valuetext="String(threshold)">
          <span class="w-12 text-end text-2xl font-bold tabular-nums">{{ threshold }}</span>
        </div>
        <p v-if="thresholdError" class="mt-2 text-sm text-danger">{{ thresholdError }}</p>
      </section>

      <section class="rounded-2xl border border-line bg-white" :aria-label="t('risk.rules')">
        <div class="px-6 pt-6">
          <h3 class="text-lg font-bold">{{ t('risk.rules') }}</h3>
          <p class="mt-1 text-sm text-muted">{{ t('risk.rulesHint') }}</p>
        </div>
        <table class="mt-4 w-full text-[15px]">
          <thead>
            <tr class="border-y border-line text-[13px] text-muted">
              <th scope="col" class="px-6 py-2.5 text-start font-medium">{{ t('risk.rule') }}</th>
              <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ t('risk.default') }}</th>
              <th scope="col" class="px-6 py-2.5 text-end font-medium">{{ t('risk.points') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in RULES" :key="r" class="border-b border-line last:border-0">
              <th scope="row" class="px-6 py-3 text-start font-normal">
                <span class="font-semibold">{{ t(`risk.rule_.${r}.label`) }}</span>
                <span class="block text-sm text-muted">{{ t(`risk.rule_.${r}.hint`) }}</span>
              </th>
              <td class="px-3 py-3 text-end text-muted tabular-nums" dir="ltr">{{ defaultOf(r) > 0 ? '+' : '' }}{{ defaultOf(r) }}</td>
              <td class="px-6 py-3 text-end">
                <div class="inline-flex items-center gap-2">
                  <button v-if="canManage && changed(r)" type="button" class="text-sm font-semibold text-indigo" @click="weights[r] = defaultOf(r)">{{ t('risk.reset') }}</button>
                  <input
                    v-model.number="weights[r]" type="number" min="-50" max="50" step="1" dir="ltr" :disabled="!canManage"
                    :aria-label="t(`risk.rule_.${r}.label`)" :aria-invalid="!!errors[r]"
                    class="w-20 rounded-lg border px-2.5 py-1.5 text-end tabular-nums outline-none focus:border-indigo disabled:bg-paper"
                    :class="errors[r] ? 'border-danger' : changed(r) ? 'border-indigo' : 'border-line'"
                  >
                </div>
                <p v-if="errors[r]" class="mt-1 text-sm text-danger">{{ errors[r] }}</p>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <div v-if="canManage" class="flex justify-end"><UiButton type="submit" :loading="saving" :disabled="!valid">{{ t('risk.save') }}</UiButton></div>
      <p v-else class="text-sm text-muted">{{ t('risk.readOnly') }}</p>
    </form>
  </div>
</template>
