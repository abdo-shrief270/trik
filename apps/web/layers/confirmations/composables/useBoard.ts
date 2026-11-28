import type { components } from '~/types/api'
import { boardQuery, eligibleFor, summarizeBulk, type BoardFilters, type Stage } from '../utils/board'

export type BoardOrder = components['schemas']['Order'] & { waiting_seconds?: number }
export type Counts = Partial<Record<Stage, number>>

/**
 * The confirmation board for the current filters: one stage, paged, plus the counts of every stage.
 * Refreshes every 20 s while the tab is visible; a selection survives refreshes (minus orders that left).
 */
export function useBoard(filters: Ref<BoardFilters>) {
  const rows = ref<BoardOrder[]>([])
  const counts = ref<Counts>({})
  const next = ref<string | null>(null)
  const loading = ref(false)
  const error = ref<ApiError | null>(null)
  const selected = ref<Set<string>>(new Set())

  async function page(cursor?: string) {
    const res = await call(useApi().GET('/orders/board', {
      params: { ...inWorkspace(), query: { ...boardQuery(filters.value), per_page: 50, ...(cursor ? { cursor } : {}) } },
    }))
    return { data: (res.data ?? []) as BoardOrder[], counts: (res.counts ?? {}) as Counts, next: res.meta?.next_cursor ?? null }
  }

  async function reload() {
    rows.value = []
    next.value = null
    selected.value = new Set()
    loading.value = true
    error.value = null
    try {
      const r = await page()
      rows.value = r.data
      counts.value = r.counts
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
      rows.value = [...rows.value, ...r.data.filter((o) => !rows.value.some((x) => x.id === o.id))]
      counts.value = r.counts
      next.value = r.next
    } finally {
      loading.value = false
    }
  }

  /** Background refresh of the first page; orders that left the stage drop out of the list and selection. */
  async function refresh() {
    try {
      const r = await page()
      const firstIds = new Set(r.data.map((o) => o.id))
      const beyond = rows.value.slice(r.data.length).filter((o) => !firstIds.has(o.id))
      rows.value = [...r.data, ...beyond]
      counts.value = r.counts
      const still = new Set(rows.value.map((o) => o.id))
      selected.value = new Set([...selected.value].filter((id) => still.has(id)))
    } catch {
      // the next refresh will try again
    }
  }

  function toggle(id: string) {
    const s = new Set(selected.value)
    if (s.has(id)) s.delete(id)
    else s.add(id)
    selected.value = s
  }
  const allSelected = computed(() => rows.value.length > 0 && rows.value.every((o) => selected.value.has(o.id)))
  function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(rows.value.map((o) => o.id))
  }

  /** Runs a bulk action on the selected orders the action applies to (max 100 per call). */
  async function bulk(action: 'confirm' | 'cancel' | 'remind', reason?: string) {
    if (!eligibleFor(action, filters.value.stage)) return null
    const ids = [...selected.value].slice(0, 100)
    if (!ids.length) return null
    const res = await call(useApi().POST('/confirmation/bulk', {
      params: { header: { ...inWorkspace().header, 'Idempotency-Key': crypto.randomUUID() } },
      body: { action, order_ids: ids, ...(reason ? { reason } : {}) },
    }))
    selected.value = new Set()
    await refresh()
    return summarizeBulk(res.data ?? [])
  }

  async function single(order: BoardOrder, action: 'confirm' | 'cancel', reason?: string) {
    const p = { ...inWorkspace(), path: { order: order.id } }
    if (action === 'confirm') await call(useApi().POST('/orders/{order}/confirm', { params: p }))
    else await call(useApi().POST('/orders/{order}/cancel', { params: p, body: reason ? { reason } : {} }))
    await refresh()
  }

  // Filters typed into inputs settle before reloading.
  let debounce: ReturnType<typeof setTimeout> | undefined
  watch(() => JSON.stringify(boardQuery(filters.value)), () => {
    clearTimeout(debounce)
    debounce = setTimeout(reload, 300)
  })
  watch(() => useSessionStore().workspaceId, reload)
  onMounted(reload)

  let timer: ReturnType<typeof setInterval> | undefined
  onMounted(() => (timer = setInterval(() => document.visibilityState === 'visible' && refresh(), 20_000)))
  onBeforeUnmount(() => {
    clearInterval(timer)
    clearTimeout(debounce)
  })

  return { rows, counts, loading, error, selected, allSelected, hasMore: computed(() => !!next.value), reload, loadMore, refresh, toggle, toggleAll, bulk, single }
}

/** Egypt's governorates (public endpoint), named in the current language. */
export function useGovernorates() {
  const { locale } = useI18n()
  const { data } = useAsyncData('governorates', async () => (await call(useApi().GET('/addresses/governorates'))).data ?? [], { default: () => [] })
  const options = computed(() => data.value.map((g) => ({ value: g.code ?? '', label: (locale.value === 'ar' ? g.name_ar : g.name_en) ?? g.code ?? '' })).sort((a, b) => a.label.localeCompare(b.label, locale.value)))
  const nameOf = (code?: string | null) => (code ? options.value.find((o) => o.value === code)?.label ?? code : null)
  return { options, nameOf }
}
