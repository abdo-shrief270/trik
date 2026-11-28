export interface RefusalInput {
  /** Cash-on-delivery orders a month. */
  orders: number
  /** Share of orders refused at the door, in percent (0–100). */
  rate: number
  /** Shipping cost of one trip, in EGP. A refused parcel travels twice. */
  ship: number
  /** Ad spend that brought one order, in EGP. */
  ad: number
}

export interface RefusalCost {
  refused: number
  shipping: number
  ads: number
  total: number
}

const clean = (n: number, max = Number.POSITIVE_INFINITY) => (Number.isFinite(n) && n > 0 ? Math.min(n, max) : 0)

/** What refused cash-on-delivery parcels cost a seller in a month. */
export function refusalCost(input: RefusalInput): RefusalCost {
  const refused = clean(input.orders) * (clean(input.rate, 100) / 100)
  const shipping = refused * clean(input.ship) * 2
  const ads = refused * clean(input.ad)
  return { refused, shipping, ads, total: shipping + ads }
}
