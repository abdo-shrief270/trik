/** Pure helpers for the inbox (tested in tests/unit/inbox.spec.ts). */

export type InboxFilter = 'open' | 'mine' | 'unassigned' | 'closed'

/** The list endpoint's query for a filter (GET /inbox/conversations). */
export function filterQuery(filter: InboxFilter, unreadOnly: boolean) {
  const q: { status: 'open' | 'closed' | 'all'; assignee?: string; unread?: boolean } = { status: filter === 'closed' ? 'closed' : 'open' }
  if (filter === 'mine') q.assignee = 'me'
  if (filter === 'unassigned') q.assignee = 'unassigned'
  if (unreadOnly) q.unread = true
  return q
}

export const isInboxFilter = (v: unknown): v is InboxFilter => v === 'open' || v === 'mine' || v === 'unassigned' || v === 'closed'

type Content = Record<string, unknown> | null | undefined

const str = (v: unknown): string | null => (typeof v === 'string' && v.trim() ? v : null)
const obj = (v: unknown): Record<string, unknown> | null => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : null)

export interface MessageView {
  text: string | null
  /** A non-text message: what it is (i18n key inbox.kind.<kind>). */
  kind: 'text' | 'image' | 'video' | 'audio' | 'document' | 'sticker' | 'location' | 'template' | 'buttons' | 'reply' | 'unsupported'
  /** Quick-reply buttons on an outbound message. */
  buttons: string[]
  /** Template name on an outbound template message. */
  template?: string
  caption?: string | null
}

/**
 * What a message says, whatever its shape. Inbound content holds parsed fields (text, media_id, reply…);
 * outbound content is the Cloud API object (text, template, interactive, media).
 */
export function messageView(type: string, content: Content): MessageView {
  const c = obj(content) ?? {}
  const base: MessageView = { text: null, kind: 'text', buttons: [] }

  // Outbound Cloud API shapes
  const text = obj(c.text)
  if (text && str(text.body)) return { ...base, text: str(text.body) }
  const tpl = obj(c.template)
  if (tpl) return { ...base, kind: 'template', template: str(tpl.name) ?? undefined, text: null }
  const interactive = obj(c.interactive)
  if (interactive) {
    const body = str(obj(interactive.body)?.text)
    const buttons = ((obj(interactive.action)?.buttons as unknown[]) ?? [])
      .map((b) => str(obj(obj(b)?.reply)?.title))
      .filter((t): t is string => !!t)
    return { ...base, kind: 'buttons', text: body, buttons }
  }

  // Inbound parsed fields (and plain outbound text)
  if (str(c.text)) return { ...base, text: str(c.text) }
  if (str(c.button_title) || str(c.reply_title)) return { ...base, kind: 'reply', text: str(c.button_title) ?? str(c.reply_title) }

  const media = ['image', 'video', 'audio', 'document', 'sticker'] as const
  for (const m of media) {
    if (type === m || obj(c[m])) return { ...base, kind: m, caption: str(c.caption) ?? str(obj(c[m])?.caption) }
  }
  if (type === 'location' || obj(c.location)) return { ...base, kind: 'location' }
  if (type === 'interactive' || type === 'button') return { ...base, kind: 'reply', text: str(c.title) }
  return { ...base, kind: 'unsupported' }
}

/** "EGP 1,250" from minor units. Western digits in Arabic too (the brand book does the same). */
export function formatMoney(money: { amount: number; currency: string } | null | undefined, locale: string): string {
  if (!money) return '—'
  const fmt = new Intl.NumberFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', {
    style: 'currency',
    currency: money.currency,
    currencyDisplay: 'code',
    maximumFractionDigits: money.amount % 100 === 0 ? 0 : 2,
  })
  return fmt.format(money.amount / 100)
}

/** Time left in the 24-hour reply window, as hours and minutes, or null once it has closed. */
export function windowLeft(closesAt: string | null | undefined, now: number): { hours: number; minutes: number } | null {
  if (!closesAt) return null
  const ms = Date.parse(closesAt) - now
  if (!Number.isFinite(ms) || ms <= 0) return null
  const minutes = Math.floor(ms / 60_000)
  return { hours: Math.floor(minutes / 60), minutes: minutes % 60 }
}

/** Messages (newest first from the API) → oldest first, grouped by calendar day (local time). */
export function groupByDay<T extends { created_at?: string }>(newestFirst: T[]): { day: string; items: T[] }[] {
  const groups: { day: string; items: T[] }[] = []
  for (const m of [...newestFirst].reverse()) {
    const d = m.created_at ? new Date(m.created_at) : null
    const day = d ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` : ''
    const last = groups[groups.length - 1]
    if (last && last.day === day) last.items.push(m)
    else groups.push({ day, items: [m] })
  }
  return groups
}

export type Tone = 'neutral' | 'indigo' | 'success' | 'warning' | 'danger' | 'ink'

/** Badge tone per order status. Ink (with the lime tick) only for a confirmed order. */
export function orderTone(status: string): Tone {
  switch (status) {
    case 'confirmed':
      return 'ink'
    case 'ready_to_ship':
    case 'shipped':
    case 'delivered':
      return 'success'
    case 'awaiting_deposit':
    case 'confirming':
      return 'warning'
    case 'cancelled':
    case 'returned':
      return 'danger'
    default:
      return 'indigo'
  }
}

/** The confirmation board's risk bands: high 60+, medium 40–59, low below 40. */
export function riskLevel(score: number): 'high' | 'medium' | 'low' {
  return score >= 60 ? 'high' : score >= 40 ? 'medium' : 'low'
}

/** Keeps the list's order (newest activity first) while merging a refreshed first page into loaded pages. */
export function mergeConversations<T extends { id?: string; last_message?: { at?: string } }>(fresh: T[], loaded: T[]): T[] {
  const byId = new Map<string, T>()
  for (const c of loaded) if (c.id) byId.set(c.id, c)
  for (const c of fresh) if (c.id) byId.set(c.id, c)
  return [...byId.values()].sort((a, b) => (Date.parse(b.last_message?.at ?? '') || 0) - (Date.parse(a.last_message?.at ?? '') || 0))
}
