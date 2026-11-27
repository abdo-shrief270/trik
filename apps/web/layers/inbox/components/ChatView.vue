<script setup lang="ts">
import type { Conversation, Message, Order } from '../composables/useInbox'
import { formatMoney, groupByDay, orderTone } from '../utils/inbox'

const props = defineProps<{
  conversation: Conversation
  messages: Message[]
  hasOlder: boolean
  loadingOlder: boolean
  orders: Order[] | null
  members: { user_id?: string; name?: string }[]
  can: (p: string) => boolean
  busyOrder: { id: string; action: 'confirm' | 'cancel' } | null
}>()
const emit = defineEmits<{
  send: [string]
  retry: [Message]
  discard: [Message]
  older: []
  assign: [string | null]
  status: ['open' | 'closed']
  orderAction: [Order, 'confirm' | 'cancel']
}>()
const { t, locale } = useI18n()
const session = useSessionStore()

const name = computed(() => props.conversation.contact?.name || formatPhone(props.conversation.contact?.phone))
const groups = computed(() => groupByDay(props.messages))
const memberName = (id?: string | null) => (id ? (id === session.user?.id ? t('inbox.youName') : props.members.find((m) => m.user_id === id)?.name ?? t('inbox.teammate')) : null)
const assignee = computed(() => memberName(props.conversation.assignee_id))
const canReply = computed(() => props.can('inbox.conversations.reply'))
const canAssignOthers = computed(() => props.can('inbox.conversations.assign') && props.members.length > 0)
const openOrder = computed(() => props.orders?.find((o) => ['new', 'confirming', 'awaiting_deposit'].includes(o.status)) ?? null)

// Keep the newest message in view when it arrives, unless the user scrolled up to read history.
const scroller = ref<HTMLElement | null>(null)
const nearBottom = ref(true)
function onScroll() {
  const el = scroller.value
  if (!el) return
  nearBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 120
  if (el.scrollTop < 80 && props.hasOlder && !props.loadingOlder) {
    const before = el.scrollHeight
    emit('older')
    // keep the reading position once older messages are prepended
    const stop = watch(() => props.messages.length, async () => {
      await nextTick()
      el.scrollTop += el.scrollHeight - before
      stop()
    })
  }
}
watch(() => props.messages[0]?.id, async () => {
  await nextTick()
  if (nearBottom.value && scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
})
watch(() => props.conversation.id, async () => {
  nearBottom.value = true
  await nextTick()
  if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
}, { immediate: true })

const composer = ref<{ focus: () => void } | null>(null)
defineExpose({ focusComposer: () => composer.value?.focus() })
</script>

<template>
  <section class="flex min-w-0 flex-1 flex-col bg-paper" :aria-label="t('inbox.chatWith', { name })">
    <!-- Header -->
    <header class="flex flex-wrap items-center gap-3 border-b border-line bg-white px-5 py-3">
      <UiAvatar :id="conversation.contact?.id" :name="name" />
      <div class="min-w-0 flex-1">
        <h2 class="truncate font-bold" dir="auto">{{ name }}</h2>
        <p class="truncate text-sm text-muted" dir="ltr">{{ formatPhone(conversation.contact?.phone) }}</p>
      </div>

      <UiMenu v-if="canAssignOthers" :label="t('inbox.assign')" placement="end" width="w-64">
        <template #trigger="{ toggle, attrs }">
          <UiButton variant="secondary" size="sm" v-bind="attrs" @click="toggle">
            <UiIcon name="user" :size="16" />{{ assignee ?? t('inbox.unassigned') }}<UiIcon name="chevronDown" :size="14" />
          </UiButton>
        </template>
        <template #default="{ close }">
          <button
            v-for="m in members"
            :key="m.user_id"
            type="button"
            role="menuitem"
            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start hover:bg-paper focus:bg-paper focus:outline-none"
            @click="emit('assign', m.user_id ?? null); close()"
          >
            <UiAvatar :id="m.user_id" :name="m.name ?? '?'" size="sm" />
            <span class="flex-1 truncate">{{ m.user_id === session.user?.id ? t('inbox.youName') : m.name }}</span>
            <UiIcon v-if="m.user_id === conversation.assignee_id" name="check" :size="16" class="text-indigo" />
          </button>
          <button
            v-if="conversation.assignee_id"
            type="button"
            role="menuitem"
            class="mt-1 flex w-full items-center gap-3 rounded-lg border-t border-line px-3 py-2 text-start text-muted hover:bg-paper focus:bg-paper focus:outline-none"
            @click="emit('assign', null); close()"
          >
            <UiIcon name="x" :size="16" />{{ t('inbox.unassign') }}
          </button>
        </template>
      </UiMenu>
      <UiButton
        v-else-if="canReply && conversation.assignee_id !== session.user?.id"
        variant="secondary"
        size="sm"
        @click="emit('assign', session.user?.id ?? null)"
      >
        <UiIcon name="user" :size="16" />{{ t('inbox.assignMe') }}
      </UiButton>
      <span v-else-if="assignee" class="text-sm text-muted">{{ assignee }}</span>

      <UiButton v-if="canReply && conversation.status === 'open'" variant="secondary" size="sm" @click="emit('status', 'closed')">
        <UiIcon name="check" :size="16" />{{ t('inbox.close') }}
      </UiButton>
      <UiButton v-else-if="canReply" variant="secondary" size="sm" @click="emit('status', 'open')">{{ t('inbox.reopen') }}</UiButton>
    </header>

    <!-- The open order, with its next step -->
    <div v-if="openOrder" class="flex flex-wrap items-center gap-3 border-b border-indigo/15 bg-indigo-soft/60 px-5 py-3">
      <BrandMark :size="30" />
      <div class="min-w-0 flex-1">
        <p class="font-semibold"><span dir="ltr">#{{ openOrder.number }}</span> · <span dir="ltr">{{ formatMoney(openOrder.total, locale) }}</span></p>
        <p class="text-sm" :class="openOrder.next_action === 'fix_address' ? 'text-warning' : 'text-muted'">
          {{ openOrder.next_action ? t(`order.next.${openOrder.next_action}`) : t(`order.status.${openOrder.status}`) }}
        </p>
      </div>
      <UiBadge :tone="orderTone(openOrder.status)">{{ t(`order.status.${openOrder.status}`) }}</UiBadge>
      <UiButton
        v-if="can('orders.orders.confirm') && openOrder.next_action === 'confirm'"
        size="sm"
        :loading="busyOrder?.id === openOrder.id && busyOrder.action === 'confirm'"
        @click="emit('orderAction', openOrder, 'confirm')"
      >
        {{ t('order.confirm') }}
      </UiButton>
    </div>

    <!-- Messages -->
    <div ref="scroller" class="flex-1 overflow-y-auto px-5 py-4" role="log" aria-live="polite" :aria-label="t('inbox.messages')" @scroll.passive="onScroll">
      <div v-if="hasOlder" class="flex justify-center py-2">
        <UiButton variant="ghost" size="sm" :loading="loadingOlder" @click="emit('older')">{{ t('inbox.older') }}</UiButton>
      </div>
      <template v-for="g in groups" :key="g.day">
        <p class="my-4 text-center text-sm font-medium text-muted">
          <span class="rounded-full bg-white px-3 py-1 shadow-sm">{{ dayLabel(g.day, locale, { today: t('inbox.today'), yesterday: t('inbox.yesterday') }) }}</span>
        </p>
        <ul class="space-y-3">
          <li v-for="m in g.items" :key="m.id">
            <MessageBubble :message="m" :sender-name="m.direction === 'out' ? memberName(m.sent_by) : null" @retry="emit('retry', $event)" @discard="emit('discard', $event)" />
          </li>
        </ul>
      </template>
      <UiEmptyState v-if="!messages.length" icon="inbox" :title="t('inbox.noMessages')" />
    </div>

    <ChatComposer ref="composer" :conversation="conversation" :can-reply="canReply" @send="emit('send', $event)" />
  </section>
</template>
