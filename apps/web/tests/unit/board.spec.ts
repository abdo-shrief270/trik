import { describe, expect, it } from 'vitest'
import { activeFilters, boardQuery, eligibleFor, emptyFilters, eventView, summarizeBulk, waiting } from '../../layers/confirmations/utils/board'

describe('boardQuery', () => {
  it('sends only what is set, amounts in minor units', () => {
    expect(boardQuery(emptyFilters())).toEqual({ stage: 'new' })
    expect(boardQuery({ ...emptyFilters('needs_call'), owner: 'me', governorate: 'C', minRisk: 60, minTotal: '500', maxTotal: '3,500', from: '2026-11-01', to: '2026-11-28' })).toEqual({
      stage: 'needs_call', owner: 'me', governorate: 'C', min_risk: 60, min_total: 50000, max_total: 350000, placed_from: '2026-11-01', placed_to: '2026-11-28',
    })
  })

  it('drops impossible or malformed values instead of sending them', () => {
    expect(boardQuery({ ...emptyFilters(), minTotal: '1000', maxTotal: '10', from: '2026-11-28', to: '2026-11-01', governorate: "C' or 1=1" })).toEqual({ stage: 'new', min_total: 100000, placed_from: '2026-11-28' })
    expect(boardQuery({ ...emptyFilters(), minTotal: 'abc', from: '28/11/2026' })).toEqual({ stage: 'new' })
  })

  it('counts ranges once', () => {
    expect(activeFilters({ ...emptyFilters(), owner: 'me', minTotal: '1', maxTotal: '2', from: '2026-11-01', to: '2026-11-02' })).toBe(3)
    expect(activeFilters(emptyFilters())).toBe(0)
  })
})

describe('waiting', () => {
  it('reads like the board design and escalates its tone', () => {
    expect(waiting(12 * 60)).toEqual({ parts: [{ n: 12, unit: 'm' }], tone: 'muted' })
    expect(waiting(3 * 3600 + 40 * 60)).toEqual({ parts: [{ n: 3, unit: 'h' }, { n: 40, unit: 'm' }], tone: 'warning' })
    expect(waiting(7 * 3600)).toEqual({ parts: [{ n: 7, unit: 'h' }], tone: 'danger' })
    expect(waiting(2 * 86400 + 3 * 3600)).toEqual({ parts: [{ n: 2, unit: 'd' }, { n: 3, unit: 'h' }], tone: 'danger' })
    expect(waiting(null).parts).toEqual([{ n: 0, unit: 'm' }])
  })
})

describe('bulk', () => {
  it('summarises per-order results', () => {
    expect(summarizeBulk([
      { result: 'ok' }, { result: 'sent' }, { result: 'skipped' }, { result: 'not_found' },
      { result: 'error', code: 'address-needs-fix' }, { result: 'error', code: 'address-needs-fix' }, { result: 'error' },
    ])).toEqual({ done: 2, skipped: 1, notFound: 1, errors: { 'address-needs-fix': 2, unknown: 1 } })
  })

  it('knows which actions make sense per stage', () => {
    expect(eligibleFor('confirm', 'new')).toBe(true)
    expect(eligibleFor('confirm', 'confirmed')).toBe(false)
    expect(eligibleFor('cancel', 'confirmed')).toBe(true)
    expect(eligibleFor('remind', 'cancelled')).toBe(false)
  })
})

describe('eventView owner', () => {
  it('names an owner change', () => expect(eventView({ type: 'owner_changed', data: { to: 'x' } }).key).toBe('board.event.owner_changed'))
})

describe('eventView', () => {
  it('labels timeline events', () => {
    expect(eventView({ type: 'status_changed', data: { from: 'new', to: 'confirmed' } })).toEqual({ key: 'board.event.status_changed', params: { from: 'new', to: 'confirmed' } })
    expect(eventView({ type: 'address_flagged' }).key).toBe('board.event.address_flagged')
    expect(eventView({ type: 'something_new' }).key).toBe('board.event.other')
  })
})
