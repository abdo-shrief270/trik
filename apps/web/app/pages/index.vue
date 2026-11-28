<script setup lang="ts">
/** Home: where work is waiting in each workspace, and what's left to set up in this one. */
const { t } = useI18n()
const session = useSessionStore()
useHead({ title: () => `${t('nav.home')} · ${t('brand.name')}` })

type Step = 'done' | 'todo' | 'noAccess' | 'needsEmail' | 'unknown' | 'soon'

/**
 * One step's state from an API read: done/todo from the data. A 403 is either an unverified email (these
 * features refuse unverified accounts, even the owner's) or a missing permission (agents).
 */
async function probe(read: () => Promise<boolean>): Promise<Step> {
  try {
    return (await read()) ? 'done' : 'todo'
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return 'soon' // not rolled out for this workspace yet
    if (!(e instanceof ApiError) || e.status !== 403) return 'unknown'
    return e.code === 'email-not-verified' ? 'needsEmail' : 'noAccess'
  }
}

const { data: setup, status } = useAsyncData(
  () => `setup:${session.workspaceId}`,
  async () => {
    const api = useApi()
    const [whatsapp, confirmation, store] = await Promise.all([
      probe(async () => ((await call(api.GET('/whatsapp/numbers', { params: inWorkspace() }))).data?.length ?? 0) > 0),
      probe(async () => !!(await call(api.GET('/confirmation/settings', { params: inWorkspace() }))).data?.enabled),
      probe(async () => ((await call(api.GET('/commerce/stores', { params: inWorkspace() }))).data ?? []).some((s) => s.status === 'active')),
    ])
    return { whatsapp, confirmation, store }
  },
  { watch: [() => session.workspaceId, () => session.user?.email_verified] },
)

const steps = computed(() => [
  { id: 'email', icon: 'mail' as const, state: (session.user?.email_verified ? 'done' : 'todo') as Step },
  { id: 'whatsapp', icon: 'phone' as const, state: setup.value?.whatsapp ?? ('unknown' as Step) },
  { id: 'confirmation', icon: 'rules' as const, state: setup.value?.confirmation ?? ('unknown' as Step) },
  { id: 'store', icon: 'store' as const, state: setup.value?.store ?? ('unknown' as Step) },
])
const doneCount = computed(() => steps.value.filter((s) => s.state === 'done').length)

const firstName = computed(() => session.user?.name?.split(/\s+/)[0] ?? '')
</script>

<template>
  <div class="mx-auto w-full max-w-6xl px-8 py-10">
    <h1 class="font-display text-4xl font-bold tracking-[-0.03em]">{{ t('home.hello', { name: firstName }) }}</h1>
    <p class="mt-2 text-lg text-muted">{{ t('home.sub', { workspace: session.workspace?.name ?? '' }) }}</p>

    <!-- Where work is waiting -->
    <ResultsCard class="mt-10" />

    <section class="mt-10" :aria-labelledby="'waiting'">
      <h2 id="waiting" class="text-sm font-semibold text-muted">{{ t('home.waiting') }}</h2>
      <ul class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <li v-for="w in session.workspaces" :key="w.id">
          <button
            type="button"
            class="flex w-full items-center gap-4 rounded-2xl border bg-white p-5 text-start transition-colors hover:border-indigo/40"
            :class="w.id === session.workspaceId ? 'border-indigo ring-4 ring-indigo/10' : 'border-line'"
            :aria-current="w.id === session.workspaceId ? 'true' : undefined"
            @click="session.selectWorkspace(w.id!)"
          >
            <UiAvatar :id="w.id" :name="w.name ?? '?'" size="lg" square />
            <span class="min-w-0 flex-1">
              <span class="block truncate text-lg font-bold">{{ w.name }}</span>
              <span class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
                <span><strong class="font-semibold text-ink tabular-nums">{{ w.badges?.unread ?? 0 }}</strong> {{ t('home.unread') }}</span>
                <span><strong class="font-semibold text-ink tabular-nums">{{ w.badges?.assigned_to_me ?? 0 }}</strong> {{ t('home.mine') }}</span>
              </span>
            </span>
          </button>
        </li>
      </ul>
    </section>

    <!-- Setup -->
    <section class="mt-12 rounded-2xl border border-line bg-white" aria-labelledby="setup">
      <header class="flex flex-wrap items-center justify-between gap-4 border-b border-line px-6 py-5">
        <div>
          <h2 id="setup" class="text-xl font-bold">{{ t('home.setupTitle') }}</h2>
          <p class="mt-1 text-muted">{{ t('home.setupSub') }}</p>
        </div>
        <div class="flex items-center gap-3" :aria-label="t('home.progress', { done: doneCount, total: steps.length })">
          <span class="text-sm font-semibold tabular-nums">{{ doneCount }}/{{ steps.length }}</span>
          <span class="h-2 w-32 overflow-hidden rounded-full bg-line"><span class="block h-full rounded-full bg-indigo transition-[width] duration-500" :style="{ width: `${(doneCount / steps.length) * 100}%` }" /></span>
        </div>
      </header>
      <ol class="divide-y divide-line">
        <li v-for="s in steps" :key="s.id" class="flex items-center gap-4 px-6 py-4">
          <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="s.state === 'done' ? 'bg-success-bg text-success' : 'bg-paper text-muted'">
            <UiIcon :name="s.state === 'done' ? 'check' : s.icon" :size="20" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="font-semibold">{{ t(`home.steps.${s.id}.title`) }}</p>
            <p class="text-sm text-muted">{{ t(`home.steps.${s.id}.body`) }}</p>
          </div>
          <UiSkeleton v-if="status === 'pending' && s.state === 'unknown'" class="h-6 w-20" />
          <UiBadge v-else-if="s.state === 'done'" tone="success">{{ t('home.state.done') }}</UiBadge>
          <UiBadge v-else-if="s.state === 'noAccess'">{{ t('home.state.noAccess') }}</UiBadge>
          <UiBadge v-else-if="s.state === 'needsEmail'" tone="warning">{{ t('home.state.needsEmail') }}</UiBadge>
          <UiBadge v-else-if="s.state === 'soon'">{{ t('home.state.soon') }}</UiBadge>
          <UiBadge v-else-if="s.state === 'todo'" tone="warning">{{ t('home.state.todo') }}</UiBadge>
        </li>
      </ol>
    </section>
  </div>
</template>
