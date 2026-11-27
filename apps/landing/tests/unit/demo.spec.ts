import { describe, expect, it } from 'vitest'
import { PLAN_BUNDLES, planFor } from '../../app/utils/plans'
import { FEATURE_SLUGS, isFeatureSlug } from '../../app/utils/features'
import { nextStep, riskLevel } from '../../app/utils/sample'

describe('planFor', () => {
  it('picks the smallest plan whose bundle covers the orders', () => {
    expect(planFor(50)).toBe(0)
    expect(planFor(PLAN_BUNDLES[0])).toBe(0)
    expect(planFor(PLAN_BUNDLES[0] + 1)).toBe(1)
    expect(planFor(PLAN_BUNDLES[1])).toBe(1)
    expect(planFor(PLAN_BUNDLES[2])).toBe(2)
  })

  it('stays on the largest plan above every bundle', () => {
    expect(planFor(1_000_000)).toBe(PLAN_BUNDLES.length - 1)
  })
})

describe('confirmation ladder (board demo)', () => {
  it('uses the board bands: high 60+, medium 40–59, low below 40', () => {
    expect([riskLevel(60), riskLevel(59), riskLevel(40), riskLevel(39)]).toEqual(['high', 'medium', 'medium', 'low'])
  })

  it('asks a deposit before the call only for high-risk orders', () => {
    expect(nextStep('whatsapp', 80)).toBe('reminder')
    expect(nextStep('reminder', 80)).toBe('deposit')
    expect(nextStep('reminder', 30)).toBe('call')
    expect(nextStep('deposit', 80)).toBe('trikd')
    expect(nextStep('call', 30)).toBe('trikd')
    expect(nextStep('trikd', 30)).toBe('trikd')
  })
})

describe('feature slugs', () => {
  it('knows every feature page and nothing else', () => {
    for (const s of FEATURE_SLUGS) expect(isFeatureSlug(s)).toBe(true)
    expect(isFeatureSlug('nope')).toBe(false)
  })
})
