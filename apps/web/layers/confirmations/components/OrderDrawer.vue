<script setup lang="ts">
import type { BoardOrder } from '../composables/useBoard'
import { eventView } from '../utils/board'

/** One order: the card, where its WhatsApp confirmation stands, and its timeline. */
const props = defineProps<{ order: BoardOrder | null; canConfirm: boolean; busy: 'confirm' | 'cancel' | null }>()
const emit = defineEmits<{ close: []; confirm: [BoardOrder]; cancel: [BoardOrder] }>()
const { t, locale } = useI18n()

const open = computed({ get: () => !!props.order, set: (v) => !v && emit('close') })

const { data: detail, status } = useAsyncData(
  () => `order-detail:${props.order?.id ?? 'none'}`,
  async () => {
    if (!props.order) return null
    const p = { ...inWorkspace(), path: { order: props.order.id } }
    const [run, timeline] = await Promise.all([
      call(useApi().GET('/orders/{order}/confirmation', { params: p })).then((r) => r.data ?? null).catch(() => null),
      call(useApi().GET('/orders/{order}/timeline', { params: p })).then((r) => r.data ?? []).catch(() => []),
    ])
    return { run, timeline }
  },
  { watch: [() => props.order?.id] },
)

// "10:44" today; "Tue · 10:44" before (shortTime is already the time for today).
const when = (iso?: string | null) => {
  if (!iso) return ''
  const short = shortTime(iso, locale.value)
  const clock = clockTime(iso, locale.value)
  return short === clock ? clock : `${short} · ${clock}`
}
const statusName = (s: string) => (s ? t(`order.status.${s}`, s) : '')
</script>

<template>
  <UiModal v-model="open" side :title="order ? t('order.title', { n: `#${order.number}` }) : ''">
    <template v-if="order">
      <OrderCard :order="order" :can-confirm="canConfirm" :busy="busy" @confirm="emit('confirm', order)" @cancel="emit('cancel', order)" />

      <section class="mt-6" :aria-label="t('board.run.title')">
        <h3 class="text-sm font-semibold text-muted">{{ t('board.run.title') }}</h3>
        <UiSkeleton v-if="status === 'pending'" class="mt-3 h-16 w-full" />
        <p v-else-if="!detail?.run" class="mt-2 text-[15px] text-muted">{{ t('board.run.none') }}</p>
        <dl v-else class="mt-3 grid grid-cols-2 gap-3 rounded-xl bg-paper p-4 text-[15px]">
          <div><dt class="text-sm text-muted">{{ t('board.run.status') }}</dt><dd class="font-semibold">{{ t(`board.run.statusName.${detail.run.status}`, detail.run.status ?? '') }}</dd></div>
          <div><dt class="text-sm text-muted">{{ t('board.run.messages') }}</dt><dd class="font-semibold tabular-nums">{{ detail.run.messages_sent ?? 0 }} · {{ t('board.run.reminders', { n: detail.run.reminders_sent ?? 0 }) }}</dd></div>
          <div v-if="detail.run.outcome" class="col-span-2"><dt class="text-sm text-muted">{{ t('board.run.outcome') }}</dt><dd class="font-semibold">{{ t(`board.run.outcomeName.${detail.run.outcome}`, detail.run.outcome) }}</dd></div>
          <div v-if="detail.run.next_action_at" class="col-span-2"><dt class="text-sm text-muted">{{ t('board.run.next') }}</dt><dd class="font-semibold">{{ when(detail.run.next_action_at) }}</dd></div>
        </dl>
      </section>

      <section class="mt-6" :aria-label="t('board.timeline')">
        <h3 class="text-sm font-semibold text-muted">{{ t('board.timeline') }}</h3>
        <ol v-if="detail?.timeline?.length" class="mt-3 space-y-3 border-s-2 border-line ps-4">
          <li v-for="(e, i) in detail.timeline" :key="i" class="relative">
            <span class="absolute -start-[1.4rem] top-1.5 size-2.5 rounded-full bg-indigo" aria-hidden="true" />
            <p class="text-[15px]">
              {{ t(eventView(e).key, { from: statusName(eventView(e).params.from ?? ''), to: statusName(eventView(e).params.to ?? '') }) }}
            </p>
            <time class="text-sm text-muted" :datetime="e.at">{{ when(e.at) }}</time>
          </li>
        </ol>
      </section>
    </template>
  </UiModal>
</template>
