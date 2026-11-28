import { describe, expect, it } from 'vitest'
import { STORE_SETTINGS, backfillView, platformName, returnNotice } from '../../layers/settings/utils/stores'
import { eventView } from '../../layers/confirmations/utils/board'

describe('backfillView', () => {
  it('says where the import stands', () => {
    expect(backfillView({ status: 'running' })).toMatchObject({ key: 'preparing', busy: true })
    expect(backfillView({ status: 'importing', imported: 120 })).toMatchObject({ key: 'importing', params: { n: 120 }, busy: true })
    expect(backfillView({ status: 'done', imported: 340, skipped: 0 })).toMatchObject({ key: 'done', tone: 'success' })
    expect(backfillView({ status: 'done', imported: 340, skipped: 4 })).toMatchObject({ key: 'doneSkipped', params: { n: 340, skipped: 4 } })
    expect(backfillView({ status: 'failed', error: 'x' })).toMatchObject({ key: 'failed', tone: 'danger', busy: false })
    expect(backfillView({ status: 'unsupported' }).key).toBe('unsupported')
    expect(backfillView(undefined).key).toBe('none')
  })
})

describe('returnNotice', () => {
  it('reads what the platforms send back', () => {
    expect(returnNotice({ shopify: 'connected', store: 'x' })).toEqual({ kind: 'success', key: 'stores.connected.shopify' })
    expect(returnNotice({ easyorders: 'connected' })).toEqual({ kind: 'success', key: 'stores.connected.easyorders' })
    expect(returnNotice({ shopify_error: 'store-connected-elsewhere' })).toEqual({ kind: 'error', key: 'stores.errors.store-connected-elsewhere' })
    expect(returnNotice({ shopify_error: '<script>' })).toEqual({ kind: 'error', key: 'stores.errors.shopify-error' })
    expect(returnNotice({ connect: 'shopify', shop: 'laila.myshopify.com' })).toEqual({ kind: 'connect', shop: 'laila.myshopify.com' })
    expect(returnNotice({ connect: 'shopify', shop: 'evil.com' })).toBeNull()
    expect(returnNotice({})).toBeNull()
  })
})

describe('stores', () => {
  it('names platforms and their settings', () => {
    expect(platformName('easyorders')).toBe('EasyOrders')
    expect(STORE_SETTINGS.shopify).toContain('cancel_in_store')
    expect(STORE_SETTINGS.easyorders).not.toContain('cancel_in_store')
  })

  it('labels the new timeline entries', () => {
    expect(eventView({ type: 'store_updated', data: { store: 'shopify', what: ['tag:trik-confirmed'] } })).toEqual({ key: 'board.event.store_updated', params: { store: 'shopify' } })
    expect(eventView({ type: 'risk_flagged', data: { score: 72 } })).toEqual({ key: 'board.event.risk_flagged', params: { score: '72' } })
    expect(eventView({ type: 'confirmation_reply', data: { action: 'confirm' } }).params).toEqual({ action: 'confirm' })
  })
})
