<script setup lang="ts">
import type { components } from '~/types/api'

// Confirmation rules (PUT /confirmation/settings): which number and approved template, reminders, escalation,
// and the automatic replies. Off until an approved template is chosen (the API enforces it too).
type Settings = components['schemas']['ConfirmationSettings']

const { t } = useI18n()
const toast = useToast()
const session = useSessionStore()
const { can, loaded } = usePermissions()
useHead({ title: () => `${t('rules.title')} · ${t('brand.name')}` })

const form = reactive({} as Settings)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const readOnly = computed(() => loaded.value && !can('confirmation.settings.manage'))

const { data, status, error } = useAsyncData(
  () => `confirmation-settings:${session.workspaceId}`,
  async () => {
    const api = useApi()
    const [settings, numbers] = await Promise.all([
      call(api.GET('/confirmation/settings', { params: inWorkspace() })),
      call(api.GET('/whatsapp/numbers', { params: inWorkspace() })).then((r) => r.data ?? []).catch(() => []),
    ])
    return { settings: settings.data ?? {}, numbers }
  },
  { watch: [() => session.workspaceId] },
)
watch(data, (d) => d && Object.assign(form, d.settings), { immediate: true })

const numberId = computed(() => form.number_id ?? data.value?.numbers[0]?.id ?? null)
const { data: templates } = useAsyncData(
  () => `templates:${numberId.value}`,
  async () => {
    if (!numberId.value) return []
    try {
      const res = await call(useApi().GET('/whatsapp/numbers/{number}/templates', { params: { ...inWorkspace(), path: { number: numberId.value } } }))
      return (res.data ?? []).filter((tpl) => tpl.status?.toUpperCase() === 'APPROVED')
    } catch {
      return []
    }
  },
  { watch: [numberId], default: () => [] },
)

const numberOptions = computed(() => (data.value?.numbers ?? []).map((n) => ({ value: n.id ?? '', label: n.display_phone_number ?? n.verified_name ?? n.external_id ?? '' })))
const templateOptions = computed(() => [{ value: '', label: t('rules.chooseTemplate') }, ...templates.value.map((tpl) => ({ value: tpl.name ?? '', label: `${tpl.name} · ${tpl.language}` }))])
const minuteOptions = [15, 30, 60, 120, 240, 720, 1440].map((m) => ({ value: String(m), label: m < 60 ? t('rules.minutes', { n: m }) : t('rules.hours', { n: m / 60 }) }))

// Selects bind strings; the API takes numbers and null.
const tpl = computed({ get: () => form.template_name ?? '', set: (v: string) => (form.template_name = v || null) })
const reminderTpl = computed({ get: () => form.reminder_template_name ?? '', set: (v: string) => (form.reminder_template_name = v || null) })
const number = computed({ get: () => numberId.value ?? '', set: (v: string) => (form.number_id = v || null) })
const reminderAfter = computed({ get: () => String(form.reminder_after_minutes ?? 120), set: (v: string) => (form.reminder_after_minutes = Number(v)) })
const escalateAfter = computed({ get: () => String(form.escalate_after_minutes ?? 120), set: (v: string) => (form.escalate_after_minutes = Number(v)) })
const maxReminders = computed({ get: () => String(form.max_reminders ?? 1), set: (v: string) => (form.max_reminders = Number(v)) })
const canEnable = computed(() => !!form.template_name)

async function save() {
  errors.value = {}
  if (form.enabled && !form.template_name) {
    errors.value.template_name = t('rules.needTemplate')
    return
  }
  saving.value = true
  try {
    const res = await call(useApi().PUT('/confirmation/settings', { params: inWorkspace(), body: { ...form } }))
    Object.assign(form, res.data ?? {})
    toast.success(t('rules.saved'))
  } catch (e) {
    if (e instanceof ApiError && e.problem.errors) {
      for (const [k, v] of Object.entries(e.problem.errors)) errors.value[k] = serverFieldKey(v[0]) ? t(serverFieldKey(v[0])!) : (v[0] ?? '')
    }
    toast.error(t(e instanceof ApiError ? problemMessageKey(e.problem) : 'errors.generic'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-8 py-10">
    <NuxtLink to="/confirmations" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo"><UiIcon name="chevronRight" :size="16" class="rotate-180 rtl:rotate-0" />{{ t('nav.confirmations') }}</NuxtLink>
    <h1 class="mt-4 font-display text-4xl font-bold tracking-[-0.03em]">{{ t('rules.title') }}</h1>
    <p class="mt-2 text-lg text-muted">{{ t('rules.sub') }}</p>

    <UiSkeleton v-if="status === 'pending'" class="mt-10 h-64 w-full" />
    <p v-else-if="error" role="alert" class="mt-10 rounded-xl bg-danger-bg px-4 py-3 text-danger">{{ t(error instanceof ApiError ? problemMessageKey(error.problem) : 'errors.generic') }}</p>
    <UiEmptyState v-else-if="!data?.numbers.length" class="mt-6" icon="phone" :title="t('rules.noNumber.title')" :body="t('rules.noNumber.body')" />

    <form v-else class="mt-10 space-y-6" novalidate @submit.prevent="save">
      <p v-if="readOnly" class="rounded-xl bg-paper px-4 py-3 text-[15px] text-muted">{{ t('rules.readOnly') }}</p>
      <fieldset :disabled="readOnly" class="space-y-6">
        <!-- On / off -->
        <section class="flex items-start justify-between gap-6 rounded-2xl border border-line bg-white p-6">
          <div>
            <h2 class="text-lg font-bold">{{ t('rules.enabled') }}</h2>
            <p class="mt-1 text-[15px] text-muted">{{ canEnable ? t('rules.enabledSub') : t('rules.needTemplate') }}</p>
          </div>
          <label class="relative inline-flex shrink-0 cursor-pointer items-center">
            <input v-model="form.enabled" type="checkbox" role="switch" class="peer sr-only" :disabled="!canEnable && !form.enabled" :aria-label="t('rules.enabled')">
            <span class="h-7 w-12 rounded-full bg-line transition-colors peer-checked:bg-indigo peer-focus-visible:ring-4 peer-focus-visible:ring-indigo/30 peer-disabled:opacity-50" />
            <span class="absolute start-1 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5" />
          </label>
        </section>

        <!-- Message -->
        <section class="space-y-5 rounded-2xl border border-line bg-white p-6">
          <h2 class="text-lg font-bold">{{ t('rules.message') }}</h2>
          <UiSelect v-model="number" :label="t('rules.number')" :options="numberOptions" />
          <div>
            <UiSelect v-model="tpl" :label="t('rules.template')" :options="templateOptions" :hint="templates.length ? t('rules.templateHint') : t('rules.noTemplates')" />
            <p v-if="errors.template_name" class="mt-1.5 text-[13px] text-danger">{{ errors.template_name }}</p>
          </div>
        </section>

        <!-- Reminders and escalation -->
        <section class="space-y-5 rounded-2xl border border-line bg-white p-6">
          <h2 class="text-lg font-bold">{{ t('rules.ladder') }}</h2>
          <div class="grid gap-5 sm:grid-cols-2">
            <UiSelect v-model="reminderAfter" :label="t('rules.reminderAfter')" :options="minuteOptions" />
            <UiSelect v-model="maxReminders" :label="t('rules.maxReminders')" :options="[0, 1, 2, 3, 4, 5].map((n) => ({ value: String(n), label: String(n) }))" />
          </div>
          <UiSelect v-model="reminderTpl" :label="t('rules.reminderTemplate')" :options="[{ value: '', label: t('rules.sameTemplate') }, ...templateOptions.slice(1)]" />
          <UiSelect v-model="escalateAfter" :label="t('rules.escalateAfter')" :options="minuteOptions" :hint="t('rules.escalateHint')" />
        </section>

        <!-- Replies -->
        <section class="space-y-5 rounded-2xl border border-line bg-white p-6">
          <div>
            <h2 class="text-lg font-bold">{{ t('rules.replies') }}</h2>
            <p class="mt-1 text-[15px] text-muted">{{ t('rules.repliesSub') }}</p>
          </div>
          <div v-for="k in ['confirmed_reply', 'cancelled_reply', 'change_reply'] as const" :key="k" class="flex flex-col gap-1.5">
            <label :for="k" class="text-sm font-semibold">{{ t(`rules.${k}`) }}</label>
            <textarea :id="k" v-model="form[k]" rows="2" maxlength="1024" dir="auto" class="rounded-lg border border-line bg-white px-3.5 py-2.5 text-[15px] outline-none focus:border-indigo focus:shadow-[0_0_0_4px_rgb(79_59_240/.14)]" />
            <p v-if="errors[k]" class="text-[13px] text-danger">{{ errors[k] }}</p>
          </div>
        </section>
      </fieldset>

      <div v-if="!readOnly" class="flex justify-end">
        <UiButton type="submit" size="lg" :loading="saving">{{ t('rules.save') }}</UiButton>
      </div>
    </form>
  </div>
</template>
