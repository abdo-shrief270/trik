<script setup lang="ts">
import type { Message, Order } from '../../composables/useInbox'
import { useConversation, useConversationList } from '../../composables/useInbox'
import { isInboxFilter, type InboxFilter } from '../../utils/inbox'

// The shared inbox: filters · conversations · chat · customer and orders. State lives in the URL (?f=, ?c=).
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const toast = useToast()
const { can, loaded: permsLoaded } = usePermissions()
useHead({ title: () => `${t('nav.inbox')} · ${t('brand.name')}` })

const filter = computed<InboxFilter>({
  get: () => (isInboxFilter(route.query.f) ? route.query.f : 'open'),
  set: (f) => router.replace({ query: { ...route.query, f, c: undefined } }),
})
const unreadOnly = ref(false)
const selected = computed(() => (typeof route.query.c === 'string' ? route.query.c : null))

const list = useConversationList(filter, unreadOnly)
const chat = useConversation(selected)

// Team names for assignment and "sent by" (needs workspace.members.view; agents fall back to "teammate").
const { data: members } = useAsyncData(
  () => `members:${session.workspaceId}`,
  async () => {
    try {
      return (await call(useApi().GET('/workspace/members', { params: inWorkspace() }))).data ?? []
    } catch {
      return []
    }
  },
  { watch: [() => session.workspaceId], default: () => [] },
)
const nameOf = (id?: string | null) => (id ? (id === session.user?.id ? t('inbox.youName') : members.value.find((m) => m.user_id === id)?.name ?? null) : null)

const groups = computed(() => [{
  id: 'queue',
  label: t('inbox.queue'),
  items: [
    { id: 'open', label: t('inbox.filters.open') },
    { id: 'mine', label: t('inbox.filters.mine'), count: session.workspace?.badges?.assigned_to_me ?? 0 },
    { id: 'unassigned', label: t('inbox.filters.unassigned') },
    { id: 'closed', label: t('inbox.filters.closed') },
  ],
}])

// Keep the open conversation's row up to date (read, assignment, status).
watch(() => chat.conversation.value, (c) => c && list.patchLocal(c))

function explain(e: unknown) {
  if (!(e instanceof ApiError)) return t('errors.generic')
  const specific = `inbox.errors.${e.code}`
  return t(specific) !== specific ? t(specific) : t(problemMessageKey(e.problem))
}

async function send(text: string, key?: string) {
  try {
    await chat.send(text, key)
    void list.refresh()
  } catch (e) {
    toast.error(explain(e))
  }
}
function retry(m: Message) {
  if (m.local) void send(m.local.text, m.local.key)
}

async function assign(userId: string | null) {
  try {
    await chat.update({ assignee_id: userId })
    toast.success(userId ? t('inbox.assignedTo', { name: nameOf(userId) ?? t('inbox.teammate') }) : t('inbox.unassignedDone'))
    void session.load()
  } catch (e) {
    toast.error(explain(e))
  }
}

async function setStatus(status: 'open' | 'closed') {
  try {
    await chat.update({ status })
    toast.success(status === 'closed' ? t('inbox.closedDone') : t('inbox.reopenedDone'))
    void list.refresh()
  } catch (e) {
    toast.error(explain(e))
  }
}

// Orders: confirm at once, cancel after a question.
const busyOrder = ref<{ id: string; action: 'confirm' | 'cancel' } | null>(null)
const cancelling = ref<Order | null>(null)
const cancelOpen = computed({ get: () => !!cancelling.value, set: (v) => { if (!v) cancelling.value = null } })

async function orderAction(order: Order, action: 'confirm' | 'cancel') {
  if (action === 'cancel' && cancelling.value?.id !== order.id) {
    cancelling.value = order
    return
  }
  cancelling.value = null
  busyOrder.value = { id: order.id, action }
  try {
    await chat.orderAction(order, action)
    toast.success(t(action === 'confirm' ? 'order.confirmedDone' : 'order.cancelledDone', { n: order.number }))
  } catch (e) {
    toast.error(explain(e))
  } finally {
    busyOrder.value = null
  }
}

// Keyboard: Esc closes the chat on narrow screens (the list comes back).
function close() {
  router.replace({ query: { ...route.query, c: undefined } })
}
</script>

<template>
  <div class="flex min-h-0 flex-1">
    <AppFilterPanel v-model="filter" :title="t('nav.inbox')" :groups="groups" class="hidden lg:flex">
      <template #action>
        <label class="flex items-center gap-2 text-sm text-muted">
          <input v-model="unreadOnly" type="checkbox" class="size-4 accent-indigo">{{ t('inbox.unreadOnly') }}
        </label>
      </template>
    </AppFilterPanel>

    <!-- Conversations -->
    <section
      class="flex w-full shrink-0 flex-col border-e border-line bg-white md:w-[340px]"
      :class="selected ? 'hidden md:flex' : 'flex'"
      :aria-label="t('inbox.conversations')"
    >
      <header class="flex h-16 items-center justify-between gap-2 border-b border-line px-4">
        <h2 class="font-bold">{{ t(`inbox.filters.${filter}`) }}</h2>
        <UiSelect v-model="filter" class="w-40 lg:hidden" :label="t('inbox.filter')" :options="(['open', 'mine', 'unassigned', 'closed'] as const).map((f) => ({ value: f, label: t(`inbox.filters.${f}`) }))" />
      </header>

      <div class="flex-1 overflow-y-auto">
        <template v-if="list.loading.value && !list.items.value.length">
          <div v-for="i in 6" :key="i" class="flex gap-3 border-b border-line px-4 py-4"><UiSkeleton class="size-10 !rounded-full" /><div class="flex-1 space-y-2"><UiSkeleton class="h-4 w-1/2" /><UiSkeleton class="h-3 w-3/4" /></div></div>
        </template>
        <div v-else-if="list.error.value" class="p-6 text-center">
          <p class="text-danger">{{ explain(list.error.value) }}</p>
          <UiButton variant="secondary" size="sm" class="mt-4" @click="list.reload()">{{ t('inbox.tryAgain') }}</UiButton>
        </div>
        <UiEmptyState v-else-if="!list.items.value.length" icon="inbox" :title="t(`inbox.empty.${filter}.title`)" :body="t(`inbox.empty.${filter}.body`)" />
        <ul v-else>
          <li v-for="c in list.items.value" :key="c.id">
            <ConversationItem :conversation="c" :active="c.id === selected" :assignee-name="nameOf(c.assignee_id)" />
          </li>
        </ul>
        <div v-if="list.hasMore.value" class="p-4 text-center">
          <UiButton variant="ghost" size="sm" :loading="list.loading.value" @click="list.loadMore()">{{ t('inbox.more') }}</UiButton>
        </div>
      </div>
    </section>

    <!-- Chat -->
    <template v-if="selected">
      <div v-if="chat.loading.value && !chat.conversation.value" class="flex flex-1 items-center justify-center bg-paper"><UiSpinner class="text-indigo" /></div>
      <div v-else-if="chat.error.value" class="flex flex-1 items-center justify-center bg-paper p-8 text-center">
        <div>
          <p class="text-danger">{{ explain(chat.error.value) }}</p>
          <UiButton variant="secondary" size="sm" class="mt-4" @click="close">{{ t('inbox.backToList') }}</UiButton>
        </div>
      </div>
      <template v-else-if="chat.conversation.value && permsLoaded">
        <ChatView
          :conversation="chat.conversation.value"
          :messages="chat.messages.value"
          :has-older="chat.hasOlder.value"
          :loading-older="chat.loadingOlder.value"
          :orders="chat.orders.value"
          :members="members"
          :can="can"
          :busy-order="busyOrder"
          @keydown.esc="close"
          @send="send"
          @retry="retry"
          @discard="chat.discard($event.id!)"
          @older="chat.loadOlder()"
          @assign="assign"
          @status="setStatus"
          @order-action="orderAction"
        />
        <CustomerPanel
          :conversation="chat.conversation.value"
          :orders="chat.orders.value"
          :orders-hidden="chat.ordersHidden.value"
          :can-confirm="can('orders.orders.confirm')"
          :busy-order="busyOrder"
          @order-action="orderAction"
        />
      </template>
    </template>
    <section v-else class="hidden flex-1 items-center justify-center bg-paper md:flex">
      <UiEmptyState icon="inbox" :title="t('inbox.pick.title')" :body="t('inbox.pick.body')" />
    </section>

    <UiModal v-model="cancelOpen" :title="t('order.cancelTitle', { n: cancelling?.number ?? '' })" size="sm">
      <p class="text-[15px] leading-relaxed text-muted">{{ t('order.cancelBody') }}</p>
      <template #footer>
        <UiButton variant="secondary" @click="cancelling = null">{{ t('order.keep') }}</UiButton>
        <UiButton variant="danger" @click="cancelling && orderAction(cancelling, 'cancel')">{{ t('order.cancelYes') }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
