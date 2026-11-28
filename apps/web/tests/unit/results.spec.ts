import { describe, expect, it } from 'vitest'
import { change, duration, pct } from '../../layers/analytics/utils/results'

describe('pilot results formatting', () => {
  it('shows rates as percentages, and nothing as a dash', () => {
    expect(pct(0.3333)).toBe('33.3%')
    expect(pct(0.125)).toBe('12.5%')
    expect(pct(0)).toBe('0%')
    expect(pct(null)).toBe('—')
    expect(pct(0.5, 'ar')).toMatch(/^٥٠٪/) // ICU adds an Arabic letter mark after ٪ for bidi
  })

  it('colours a change by whether it is good news for that metric', () => {
    expect(change(-20.8, 'refusal')).toEqual({ text: '−20.8', tone: 'success', sign: -1 })
    expect(change(4, 'refusal')).toMatchObject({ text: '+4', tone: 'danger' })
    expect(change(3.5, 'confirmation')).toMatchObject({ tone: 'success' })
    expect(change(-1.1, 'confirmation')).toMatchObject({ tone: 'danger' })
    expect(change(1.1, 'cancellation')).toMatchObject({ tone: 'danger' })
    expect(change(0, 'refusal')).toMatchObject({ text: '0', tone: 'neutral' })
    expect(change(null, 'refusal')).toEqual({ text: '—', tone: 'neutral', sign: 0 })
  })

  it('reads durations', () => {
    const u = { h: 'h', min: 'min' }
    expect(duration(90, u)).toBe('1 h 30 min')
    expect(duration(12.5, u)).toBe('13 min')
    expect(duration(120, u)).toBe('2 h')
    expect(duration(null, u)).toBe('—')
    expect(duration(65, { h: 'س', min: 'د' }, 'ar')).toBe('١ س ٥ د')
  })
})
