import { describe, expect, it } from 'vitest'
import { refusalCost } from '../../app/utils/refusal'

describe('refusalCost', () => {
  it('charges shipping both ways plus the ad for every refused parcel', () => {
    expect(refusalCost({ orders: 1000, rate: 25, ship: 60, ad: 40 })).toEqual({
      refused: 250,
      shipping: 30000,
      ads: 10000,
      total: 40000,
    })
  })

  it('is zero with no refusals', () => {
    expect(refusalCost({ orders: 500, rate: 0, ship: 60, ad: 40 }).total).toBe(0)
  })

  it('caps the rate at 100% and ignores negative or missing numbers', () => {
    expect(refusalCost({ orders: 10, rate: 250, ship: 10, ad: 0 }).refused).toBe(10)
    expect(refusalCost({ orders: -5, rate: 20, ship: 10, ad: 10 }).total).toBe(0)
    expect(refusalCost({ orders: Number.NaN, rate: 20, ship: 10, ad: 10 }).total).toBe(0)
  })
})
