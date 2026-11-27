import type { components } from '~/types/api'
import { filterQuery, mergeConversations, type InboxFilter } from '../utils/inbox'

export type Conversation = components['schemas']['Conversation']
export type Message = components['schemas']['WhatsAppMessage'] & { local?: { state: 'sending' | 'failed'; key: string; text: string } }
export type Order = components['schemas']['Order']

/** Runs `fn` every `ms` while the tab is visible (until real-time delivery is wired, see README). */
function usePoll(fn: () => unknown, ms: number) {
  let timer: ReturnType<typeof setInterval> | undefined
  onMounted(() => {
    timer = setInterval(() => document.visibilityState === 'visible' && fn(), ms)
  })
  onBeforeUnmount(() => clearInterval(timer))
}

/** The conversation list for a filter: cursor pages, refreshed every 15 s without losing loaded pages. */
export function useConversationList(filter: Ref<InboxFilter>, unreadOnly: Ref<boolean>) {
  const items = ref<Conversation[]>([])
  const next = ref<string | null>(null)
  const loading = ref(false)
  const error = ref<ApiError | null>(null)

  async function page(cursor?: string) {
    const res = await call(useApi().GET('/inbox/conversations', {
      params: { ...inWorkspace(), query: { ...filterQuery(filter.value, unreadOnly.value), per_page: 30, ...(cursor ? { cursor } : {}) } },
    }))
    return { data: res.data ?? [], next: res.meta?.next_cursor ?? null }
  }

  async function reload() {
    // A different filter or workspace: never show the previous list while the new one loads.
    items.value = []
    next.value = null
    loading.value = true
    error.value = null
    try {
      const r = await page()
      items.value = r.data
      next.value = r.next
    } catch (e) {
      error.value = e instanceof ApiError ? e : new ApiError(toProblem(0, null))
    } finally {
      loading.value = false
    }
  }

  async function loadMore() {
    if (!next.value || loading.value) return
    loading.value = true
    try {
      const r = await page(next.value)
      items.value = [...items.value, ...r.data.filter((c) => !items.value.some((x) => x.id === c.id))]
      next.value = r.next
    } catch (e) {
      error.value = e instanceof ApiError ? e : null
    } finally {
      loading.value = false
    }
  }

  /** Background refresh: merge the first page into what's loaded (order by latest activity). */
  async function refresh() {
    try {
      const r = await page()
      // Conversations that left the filter (closed, reassigned) disappear from the first page: keep only
      // those still on it or on pages beyond it.
      const firstIds = new Set(r.data.map((c) => c.id))
      const oldest = r.data.at(-1)?.last_message?.at
      const beyond = items.value.filter((c) => !firstIds.has(c.id) && oldest && (c.last_message?.at ?? '') < oldest)
      items.value = mergeConversations(r.data, beyond)
    } catch {
      // A failed background refresh is not worth an error on screen; the next one will try again.
    }
  }

  function patchLocal(c: Conversation) {
    const i = items.value.findIndex((x) => x.id === c.id)
    if (i >= 0) items.value[i] = c
  }

  watch([filter, unreadOnly, () => useSessionStore().workspaceId], reload, { immediate: true })
  usePoll(refresh, 15_000)

  return { items, hasMore: computed(() => !!next.value), loading, error, reload, loadMore, refresh, patchLocal }
}

/** One open conversation: messages (newest first, older pages on demand), sending, read state, orders. */
export function useConversation(id: Ref<string | null>) {
  const conversation = ref<Conversation | null>(null)
  const messages = ref<Message[]>([])
  const older = ref<string | null>(null)
  const loading = ref(false)
  const loadingOlder = ref(false)
  const error = ref<ApiError | null>(null)
  const orders = ref<Order[] | null>(null)
  const ordersHidden = ref(false)

  const path = (cid: string) => ({ ...inWorkspace(), path: { conversation: cid } })

  async function fetchMessages(cid: string, cursor?: string) {
    const res = await call(useApi().GET('/inbox/conversations/{conversation}/messages', {
      params: { ...path(cid), query: { per_page: 40, ...(cursor ? { cursor } : {}) } },
    }))
    return { data: (res.data ?? []) as Message[], next: res.meta?.next_cursor ?? null }
  }

  async function open(cid: string) {
    loading.value = true
    error.value = null
    orders.value = null
    try {
      const [c, m] = await Promise.all([
        call(useApi().GET('/inbox/conversations/{conversation}', { params: path(cid) })),
        fetchMessages(cid),
      ])
      if (id.value !== cid) return // switched away meanwhile
      conversation.value = c.data ?? null
      messages.value = m.data
      older.value = m.next
      void loadOrders(cid)
      if ((conversation.value?.unread_count ?? 0) > 0) void markRead(cid)
    } catch (e) {
      error.value = e instanceof ApiError ? e : new ApiError(toProblem(0, null))
    } finally {
      loading.value = false
    }
  }

  async function loadOlder() {
    const cid = id.value
    if (!cid || !older.value || loadingOlder.value) return
    loadingOlder.value = true
    try {
      const m = await fetchMessages(cid, older.value)
      messages.value = [...messages.value, ...m.data.filter((x) => !messages.value.some((y) => y.id === x.id))]
      older.value = m.next
    } finally {
      loadingOlder.value = false
    }
  }

  /** Background refresh of the newest page: new messages and status changes (sent → delivered → read). */
  async function refresh() {
    const cid = id.value
    if (!cid || loading.value) return
    try {
      const [c, m] = await Promise.all([call(useApi().GET('/inbox/conversations/{conversation}', { params: path(cid) })), fetchMessages(cid)])
      if (id.value !== cid) return
      conversation.value = c.data ?? conversation.value
      const fresh = new Map(m.data.map((x) => [x.id, x]))
      const pending = messages.value.filter((x) => x.local)
      const kept = messages.value.filter((x) => !x.local && !fresh.has(x.id))
      messages.value = [...pending, ...m.data, ...kept.filter((x) => (x.created_at ?? '') < (m.data.at(-1)?.created_at ?? ''))]
      if ((conversation.value?.unread_count ?? 0) > 0) void markRead(cid)
    } catch {
      // try again next tick
    }
  }

  async function markRead(cid: string) {
    try {
      const res = await call(useApi().POST('/inbox/conversations/{conversation}/read', { params: path(cid) }))
      if (id.value === cid && res.data) conversation.value = res.data
      void useSessionStore().load() // the rail and switcher badges
    } catch {
      // not critical
    }
  }

  /**
   * Sends a text reply. The message shows at once; the Idempotency-Key is kept per message, so a retry after a
   * network error can never send it twice.
   */
  async function send(text: string, key: string = crypto.randomUUID()): Promise<void> {
    const cid = id.value
    if (!cid) return
    const temp: Message = {
      id: `local-${key}`, direction: 'out', type: 'text', status: 'queued', created_at: new Date().toISOString(),
      // The contract leaves `content` open (the Cloud API object); this is the text shape the API stores.
      content: { type: 'text', text: { body: text } } as unknown as Message['content'], local: { state: 'sending', key, text },
    }
    messages.value = [temp, ...messages.value.filter((m) => m.id !== temp.id)]
    try {
      const res = await call(useApi().POST('/inbox/conversations/{conversation}/messages', {
        params: { ...path(cid), header: { ...inWorkspace().header, 'Idempotency-Key': key } },
        body: { type: 'text', text },
      }))
      messages.value = messages.value.map((m) => (m.id === temp.id && res.data ? (res.data as Message) : m))
    } catch (e) {
      messages.value = messages.value.map((m) => (m.id === temp.id ? { ...m, local: { state: 'failed', key, text } } : m))
      throw e
    }
  }

  function discard(localId: string) {
    messages.value = messages.value.filter((m) => m.id !== localId)
  }

  async function update(body: { status?: 'open' | 'closed'; assignee_id?: string | null }) {
    const cid = id.value
    if (!cid) return null
    const res = await call(useApi().PATCH('/inbox/conversations/{conversation}', { params: path(cid), body }))
    conversation.value = res.data ?? conversation.value
    return conversation.value
  }

  async function loadOrders(cid: string) {
    try {
      const res = await call(useApi().GET('/inbox/conversations/{conversation}/orders', { params: path(cid) }))
      if (id.value === cid) {
        orders.value = res.data ?? []
        ordersHidden.value = false
      }
    } catch (e) {
      if (e instanceof ApiError && e.status === 403) ordersHidden.value = true
      orders.value = []
    }
  }

  async function orderAction(order: Order, action: 'confirm' | 'cancel') {
    const p = { ...inWorkspace(), path: { order: order.id } }
    const res = action === 'confirm'
      ? await call(useApi().POST('/orders/{order}/confirm', { params: p }))
      : await call(useApi().POST('/orders/{order}/cancel', { params: p, body: {} }))
    const updated = (res as { data?: Order }).data
    if (updated && orders.value) orders.value = orders.value.map((o) => (o.id === updated.id ? updated : o))
    return updated
  }

  watch(id, (cid) => {
    conversation.value = null
    messages.value = []
    older.value = null
    if (cid) void open(cid)
  }, { immediate: true })
  usePoll(refresh, 5_000)

  return {
    conversation, messages, loading, loadingOlder, error, orders, ordersHidden,
    hasOlder: computed(() => !!older.value),
    loadOlder, refresh, send, discard, update, orderAction,
  }
}
