/** Pure helpers for the confirmation board (tested in tests/unit/board.spec.ts). */

export const STAGES = ['new', 'awaiting_customer', 'needs_call', 'confirmed', 'cancelled'] as const
export type Stage = (typeof STAGES)[number]
export const isStage = (v: unknown): v is Stage => typeof v === 'string' && (STAGES as readonly string[]).includes(v)

export interface BoardFilters {
  stage: Stage
  owner: '' | 'me' | 'unassigned'
  governorate: string
  minRisk: 0 | 40 | 60
  minTotal: string
  maxTotal: string
  from: string
  to: string
}

export const emptyFilters = (stage: Stage = 'new'): BoardFilters => ({
  stage, owner: '', governorate: '', minRisk: 0, minTotal: '', maxTotal: '', from: '', to: '',
})

/** Amounts are typed in pounds; the API takes minor units. Bad or negative input is ignored. */
function pounds(v: string): number | undefined {
  // "3,500" → 3500; letters alone leave nothing (and Number('') would be 0, a filter nobody asked for).
  const digits = v.replace(/[^\d.]/g, '')
  if (!/\d/.test(digits)) return undefined
  const n = Number(digits)
  return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : undefined
}
const isDate = (v: string) => /^\d{4}-\d{2}-\d{2}$/.test(v)

export interface BoardQuery {
  stage: Stage
  owner?: string
  governorate?: string
  min_risk?: number
  min_total?: number
  max_total?: number
  placed_from?: string
  placed_to?: string
}

/** GET /orders/board query for the filters (only what's set). */
export function boardQuery(f: BoardFilters): BoardQuery {
  const q: BoardQuery = { stage: f.stage }
  if (f.owner) q.owner = f.owner
  // The API uses the ISO 3166-2:EG suffix (C, GZ, ALX…), as GET /addresses/governorates returns it.
  if (/^[A-Z]{1,3}$/.test(f.governorate)) q.governorate = f.governorate
  if (f.minRisk) q.min_risk = f.minRisk
  const min = pounds(f.minTotal)
  const max = pounds(f.maxTotal)
  if (min !== undefined) q.min_total = min
  if (max !== undefined && (min === undefined || max >= min)) q.max_total = max
  if (isDate(f.from)) q.placed_from = f.from
  if (isDate(f.to) && (!isDate(f.from) || f.to >= f.from)) q.placed_to = f.to
  return q
}

/** How many filter groups (besides the stage) are on; an amount or date range counts once. */
export function activeFilters(f: BoardFilters): number {
  const q = boardQuery(f)
  return [
    q.owner !== undefined,
    q.governorate !== undefined,
    q.min_risk !== undefined,
    q.min_total !== undefined || q.max_total !== undefined,
    q.placed_from !== undefined || q.placed_to !== undefined,
  ].filter(Boolean).length
}

/** The ageing timer: "12 min", "7 h 12 min", "2 d 3 h"; amber after an hour, red after six. */
export function waiting(seconds: number | null | undefined): { parts: { n: number; unit: 'd' | 'h' | 'm' }[]; tone: 'muted' | 'warning' | 'danger' } {
  const s = Math.max(0, Math.floor(seconds ?? 0))
  const d = Math.floor(s / 86_400)
  const h = Math.floor((s % 86_400) / 3_600)
  const m = Math.floor((s % 3_600) / 60)
  const parts: { n: number; unit: 'd' | 'h' | 'm' }[] = d ? [{ n: d, unit: 'd' }, { n: h, unit: 'h' }] : h ? [{ n: h, unit: 'h' }, { n: m, unit: 'm' }] : [{ n: m, unit: 'm' }]
  return { parts: parts.filter((p, i) => i === 0 || p.n > 0), tone: s >= 6 * 3_600 ? 'danger' : s >= 3_600 ? 'warning' : 'muted' }
}

export interface BulkResult { order_id?: string; result?: string; code?: string }

/** One line per outcome for the toast after a bulk action: done, skipped, failed (by reason). */
export function summarizeBulk(results: BulkResult[]): { done: number; skipped: number; notFound: number; errors: Record<string, number> } {
  const out = { done: 0, skipped: 0, notFound: 0, errors: {} as Record<string, number> }
  for (const r of results) {
    if (r.result === 'ok' || r.result === 'sent' || r.result === 'started') out.done++
    else if (r.result === 'skipped') out.skipped++
    else if (r.result === 'not_found') out.notFound++
    else {
      const code = r.code || 'unknown'
      out.errors[code] = (out.errors[code] ?? 0) + 1
    }
  }
  return out
}

/** Which orders in a selection a bulk action applies to; the API skips the rest anyway. */
export function eligibleFor(action: 'confirm' | 'cancel' | 'remind', stage: Stage): boolean {
  if (stage === 'cancelled') return false
  if (stage === 'confirmed') return action === 'cancel'
  return true
}

/** i18n key and params for a timeline event. */
export function eventView(e: { type?: string; data?: Record<string, unknown> | null }): { key: string; params: Record<string, string> } {
  const data = e.data ?? {}
  const s = (v: unknown) => (typeof v === 'string' ? v : '')
  switch (e.type) {
    case 'status_changed':
      return { key: 'board.event.status_changed', params: { from: s(data.from), to: s(data.to) } }
    case 'created':
    case 'updated':
    case 'address_fixed':
    case 'address_flagged':
    case 'address_unflagged':
    case 'note_changed':
    case 'owner_changed':
    case 'confirmation_sent':
    case 'confirmation_reminder':
    case 'confirmation_escalated':
    case 'needs_call':
    case 'needs_call_cleared':
      return { key: `board.event.${e.type}`, params: {} }
    case 'confirmation_reply':
      return { key: 'board.event.confirmation_reply', params: { action: s(data.action) } }
    case 'confirmation_failed':
      return { key: 'board.event.confirmation_failed', params: {} }
    case 'store_updated':
    case 'store_update_failed':
      return { key: `board.event.${e.type}`, params: { store: s(data.store) } }
    case 'risk_flagged':
      return { key: 'board.event.risk_flagged', params: { score: typeof data.score === 'number' ? String(data.score) : '' } }
    default:
      return { key: 'board.event.other', params: {} }
  }
}
