<script setup lang="ts">
import { eventView } from '../utils/board'

/** Where an order's WhatsApp confirmation stands, and its timeline. Used by the board drawer and the order page. */
const props = defineProps<{ orderId: string; refreshKey?: number }>()
const { t, locale } = useI18n()
const session = useSessionStore()

const { data, status } = useAsyncData(
  () => `order-activity:${props.orderId}:${props.refreshKey ?? 0}`,
  async () => {
    const p = { ...inWorkspace(), path: { order: props.orderId } }
    const [run, timeline] = await Promise.all([
      call(useApi().GET('/orders/{order}/confirmation', { params: p })).then((r) => r.data ?? null).catch(() => null),
      call(useApi().GET('/orders/{order}/timeline', { params: p })).then((r) => r.data ?? []).catch(() => []),
    ])
    return { run, timeline }
  },
  { watch: [() => props.orderId, () => props.refreshKey] },
)

// "10:44" today; "Tue · 10:44" before (shortTime is already the time for today).
const when = (iso?: string | null) => {
  if (!iso) return ''
  const short = shortTime(iso, locale.value)
  const clock = clockTime(iso, locale.value)
  return short === clock ? clock : `${short} · ${clock}`
}
const statusName = (s: string) => (s ? t(`order.status.${s}`, s) : '')
const actor = (id?: string | null) => (id ? (id === session.user?.id ? t('inbox.youName') : null) : null)
</script>

<template>
  <div>
    <section :aria-label="t('board.run.title')">
      <h3 class="text-sm font-semibold text-muted">{{ t('board.run.title') }}</h3>
      <UiSkeleton v-if="status === 'pending'" class="mt-3 h-16 w-full" />
      <p v-else-if="!data?.run" class="mt-2 text-[15px] text-muted">{{ t('board.run.none') }}</p>
      <dl v-else class="mt-3 grid grid-cols-2 gap-3 rounded-xl bg-paper p-4 text-[15px]">
        <div><dt class="text-sm text-muted">{{ t('board.run.status') }}</dt><dd class="font-semibold">{{ t(`board.run.statusName.${data.run.status}`, data.run.status ?? '') }}</dd></div>
        <div><dt class="text-sm text-muted">{{ t('board.run.messages') }}</dt><dd class="font-semibold tabular-nums">{{ data.run.messages_sent ?? 0 }} · {{ t('board.run.reminders', { n: data.run.reminders_sent ?? 0 }) }}</dd></div>
        <div v-if="data.run.outcome" class="col-span-2"><dt class="text-sm text-muted">{{ t('board.run.outcome') }}</dt><dd class="font-semibold">{{ t(`board.run.outcomeName.${data.run.outcome}`, data.run.outcome) }}</dd></div>
        <div v-if="data.run.next_action_at" class="col-span-2"><dt class="text-sm text-muted">{{ t('board.run.next') }}</dt><dd class="font-semibold">{{ when(data.run.next_action_at) }}</dd></div>
      </dl>
    </section>

    <section class="mt-6" :aria-label="t('board.timeline')">
      <h3 class="text-sm font-semibold text-muted">{{ t('board.timeline') }}</h3>
      <ol v-if="data?.timeline?.length" class="mt-3 space-y-3 border-s-2 border-line ps-4">
        <li v-for="(e, i) in data.timeline" :key="i" class="relative">
          <span class="absolute -start-[1.4rem] top-1.5 size-2.5 rounded-full bg-indigo" aria-hidden="true" />
          <p class="text-[15px]">
            {{ t(eventView(e).key, { from: statusName(eventView(e).params.from ?? ''), to: statusName(eventView(e).params.to ?? '') }) }}
          </p>
          <p class="text-sm text-muted"><time :datetime="e.at">{{ when(e.at) }}</time><template v-if="actor(e.actor_id)"> · {{ actor(e.actor_id) }}</template></p>
        </li>
      </ol>
    </section>
  </div>
</template>
