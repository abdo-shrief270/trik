/**
 * Order bundles per plan. Draft numbers until pricing is set with the pilot sellers (#147, #148):
 * change them here and the pricing page and plan finder follow.
 */
export const PLAN_BUNDLES = [300, 1500, 5000] as const

/** Index of the smallest plan whose bundle covers the monthly orders (the last plan above that). */
export function planFor(ordersPerMonth: number): number {
  const i = PLAN_BUNDLES.findIndex((bundle) => ordersPerMonth <= bundle)
  return i === -1 ? PLAN_BUNDLES.length - 1 : i
}
