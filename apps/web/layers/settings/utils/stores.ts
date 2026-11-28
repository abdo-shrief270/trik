/** Pure helpers for the stores settings page (tested in tests/unit/stores.spec.ts). */

export type Platform = 'shopify' | 'easyorders'

export interface BackfillLike {
  status?: string
  imported?: number
  skipped?: number
  since?: string | null
  error?: string | null
}

export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info'

/** The write-back switches each platform has, in display order (the API refuses the others). */
export const STORE_SETTINGS: Record<Platform, string[]> = {
  shopify: ['tag_orders', 'write_address', 'cancel_in_store'],
  easyorders: ['write_status', 'write_address'],
}

export const platformName = (p: string | undefined) => (p === 'easyorders' ? 'EasyOrders' : p === 'shopify' ? 'Shopify' : (p ?? ''))

/** What the import line says: an i18n key under stores.backfill, its params and a tone. */
export function backfillView(b: BackfillLike | undefined): { key: string; params: Record<string, number>; tone: Tone; busy: boolean } {
  const imported = b?.imported ?? 0
  const skipped = b?.skipped ?? 0
  switch (b?.status) {
    case 'queued':
    case 'running':
      return { key: 'preparing', params: {}, tone: 'info', busy: true }
    case 'importing':
      return { key: 'importing', params: { n: imported }, tone: 'info', busy: true }
    case 'done':
      return { key: skipped ? 'doneSkipped' : 'done', params: { n: imported, skipped }, tone: 'success', busy: false }
    case 'failed':
      return { key: 'failed', params: {}, tone: 'danger', busy: false }
    case 'unsupported':
      return { key: 'unsupported', params: {}, tone: 'neutral', busy: false }
    default:
      return { key: 'none', params: {}, tone: 'neutral', busy: false }
  }
}

export type ReturnNotice =
  | { kind: 'success'; key: string }
  | { kind: 'error'; key: string }
  | { kind: 'connect'; shop: string }
  | null

const ERRORS = ['invalid-signature', 'invalid-shop', 'expired', 'shop-mismatch', 'forbidden', 'store-connected-elsewhere', 'denied', 'shopify-error']

/**
 * What the query says when the browser comes back from a store platform:
 * ?shopify=connected, ?shopify_error=<code>, ?connect=shopify&shop=<domain> (arrived from Shopify), ?easyorders=connected.
 */
export function returnNotice(q: Record<string, unknown>): ReturnNotice {
  const str = (v: unknown) => (Array.isArray(v) ? v[0] : v)
  if (str(q.shopify) === 'connected') return { kind: 'success', key: 'stores.connected.shopify' }
  if (str(q.easyorders) === 'connected') return { kind: 'success', key: 'stores.connected.easyorders' }
  const err = str(q.shopify_error)
  if (typeof err === 'string') return { kind: 'error', key: `stores.errors.${ERRORS.includes(err) ? err : 'shopify-error'}` }
  const shop = str(q.shop)
  if (str(q.connect) === 'shopify' && typeof shop === 'string' && /^[a-z0-9][a-z0-9-]*\.myshopify\.com$/.test(shop)) return { kind: 'connect', shop }
  return null
}
