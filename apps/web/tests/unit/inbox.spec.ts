import { describe, expect, it } from 'vitest'
import { filterQuery, formatMoney, groupByDay, mergeConversations, messageView, orderTone, riskLevel, windowLeft } from '../../layers/inbox/utils/inbox'

describe('filterQuery', () => {
  it('maps the inbox filters to the list endpoint', () => {
    expect(filterQuery('open', false)).toEqual({ status: 'open' })
    expect(filterQuery('mine', false)).toEqual({ status: 'open', assignee: 'me' })
    expect(filterQuery('unassigned', true)).toEqual({ status: 'open', assignee: 'unassigned', unread: true })
    expect(filterQuery('closed', false)).toEqual({ status: 'closed' })
  })
})

describe('messageView', () => {
  it('reads inbound parsed fields', () => {
    expect(messageView('text', { text: 'فين الأوردر؟', contact_name: 'Mona' })).toMatchObject({ kind: 'text', text: 'فين الأوردر؟' })
    expect(messageView('image', { media_id: 'm1', mime_type: 'image/jpeg', caption: 'المقاس' })).toMatchObject({ kind: 'image', caption: 'المقاس' })
    expect(messageView('location', { latitude: 30 })).toMatchObject({ kind: 'location' })
  })

  it('reads outbound Cloud API objects', () => {
    expect(messageView('text', { type: 'text', text: { body: 'Hi Mona' } })).toMatchObject({ kind: 'text', text: 'Hi Mona' })
    expect(messageView('template', { type: 'template', template: { name: 'order_confirmation', language: { code: 'ar' } } })).toMatchObject({ kind: 'template', template: 'order_confirmation' })
    expect(messageView('interactive', {
      type: 'interactive',
      interactive: { type: 'button', body: { text: 'Confirm #1048?' }, action: { buttons: [{ type: 'reply', reply: { id: 'confirm', title: 'تأكيد' } }, { type: 'reply', reply: { id: 'edit', title: 'تعديل' } }] } },
    })).toEqual({ kind: 'buttons', text: 'Confirm #1048?', buttons: ['تأكيد', 'تعديل'] })
  })

  it('never throws on strange content', () => {
    expect(messageView('reaction', null).kind).toBe('unsupported')
    expect(messageView('text', { text: { body: 42 } } as never).kind).toBe('unsupported')
  })
})

describe('formatMoney', () => {
  // Intl puts a non-breaking space after the code (it keeps "EGP" and the amount on one line).
  const plain = (s: string) => s.replace(/\s/g, ' ')
  it('uses minor units and western digits in both languages', () => {
    expect(plain(formatMoney({ amount: 125000, currency: 'EGP' }, 'en'))).toBe('EGP 1,250')
    expect(plain(formatMoney({ amount: 125050, currency: 'EGP' }, 'en'))).toBe('EGP 1,250.50')
    expect(formatMoney({ amount: 125000, currency: 'EGP' }, 'ar')).toMatch(/1,250/)
    expect(formatMoney(null, 'en')).toBe('—')
  })
})

describe('windowLeft', () => {
  const now = Date.parse('2026-11-28T10:00:00Z')
  it('counts down to the window closing', () => {
    expect(windowLeft('2026-11-29T09:18:30Z', now)).toEqual({ hours: 23, minutes: 18 })
    expect(windowLeft('2026-11-28T10:00:59Z', now)).toEqual({ hours: 0, minutes: 0 })
  })
  it('is null once closed or unknown', () => {
    expect(windowLeft('2026-11-28T09:59:00Z', now)).toBeNull()
    expect(windowLeft(null, now)).toBeNull()
    expect(windowLeft('garbage', now)).toBeNull()
  })
})

describe('groupByDay', () => {
  it('turns newest-first into oldest-first day groups', () => {
    const m = (id: string, at: string) => ({ id, created_at: at })
    const groups = groupByDay([m('c', '2026-11-28T12:00:00'), m('b', '2026-11-28T09:00:00'), m('a', '2026-11-27T20:00:00')])
    expect(groups.map((g) => [g.day, g.items.map((i) => i.id)])).toEqual([
      ['2026-11-27', ['a']],
      ['2026-11-28', ['b', 'c']],
    ])
  })
})

describe('orders and list', () => {
  it('gives confirmed orders the ink (lime tick) tone and nothing else', () => {
    expect(orderTone('confirmed')).toBe('ink')
    expect(['new', 'confirming', 'awaiting_deposit', 'shipped', 'cancelled'].map(orderTone)).not.toContain('ink')
  })

  it('uses the board risk bands', () => {
    expect([riskLevel(72), riskLevel(59), riskLevel(40), riskLevel(12)]).toEqual(['high', 'medium', 'medium', 'low'])
  })

  it('merges a refreshed first page into loaded pages, newest first, without duplicates', () => {
    const c = (id: string, at: string) => ({ id, last_message: { at } })
    const merged = mergeConversations([c('b', '2026-11-28T12:00:00Z'), c('a', '2026-11-28T11:00:00Z')], [c('a', '2026-11-28T09:00:00Z'), c('z', '2026-11-27T09:00:00Z')])
    expect(merged.map((x) => x.id)).toEqual(['b', 'a', 'z'])
    expect(merged.find((x) => x.id === 'a')?.last_message.at).toBe('2026-11-28T11:00:00Z')
  })
})
