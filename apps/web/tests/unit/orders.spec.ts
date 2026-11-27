import { describe, expect, it } from 'vitest'
import { addressBody, addressForm, addressLooksComplete, draftTotals, emptyAddress, itemsSummary, parsePounds, validateDraft } from '../../layers/orders/utils/orders'

describe('parsePounds', () => {
  it.each([
    ['1250', 125000], ['1,250.50', 125050], ['١٢٥٠', 125000], ['٣٥٠٫٥', 35050], ['0', 0], [' 99 ', 9900],
  ])('%s → %s piastres', (input, out) => expect(parsePounds(input)).toBe(out))

  it.each(['', 'abc', '-5', '1.234', '1e3'])('rejects %j', (input) => expect(parsePounds(input)).toBeNull())
})

describe('draft order', () => {
  const items = [{ title: 'فستان كتان', variant: 'M', quantity: '2', price: '1,250' }, { title: 'شنطة', variant: '', quantity: '1', price: '890.50' }]

  it('computes totals like the API (COD collects the total)', () => {
    expect(draftTotals(items, '60', '100', 'cod')).toEqual({ subtotal: 339050, shipping: 6000, discount: 10000, total: 335050, cod: 335050 })
    expect(draftTotals(items, '', '', 'prepaid').cod).toBe(0)
    expect(draftTotals([{ title: 'x', variant: '', quantity: '1', price: '10' }], '0', '999', 'cod').total).toBe(0)
  })

  it('validates like POST /orders', () => {
    expect(validateDraft({ phone: '01012345678', items, shipping: '60', discount: '' })).toEqual({})
    expect(validateDraft({ phone: '', items: [], shipping: 'x', discount: '' })).toEqual({ phone: 'required', items: 'required', shipping: 'money' })
    expect(validateDraft({ phone: '٠١٠١٢٣٤٥٦٧٨', items: [{ title: ' ', variant: '', quantity: '0', price: 'free' }], shipping: '', discount: '' })).toEqual({
      'item.0.title': 'required', 'item.0.quantity': 'quantity', 'item.0.price': 'money',
    })
    expect(validateDraft({ phone: '123', items, shipping: '', discount: '' }).phone).toBe('phone')
  })
})

describe('address', () => {
  it('round-trips and clears empty fields to null', () => {
    const f = addressForm({ raw: 'المعادي', governorate: 'C', street: 'شارع ٩', building: null })
    expect(f.building).toBe('')
    expect(addressBody({ ...f, area: '  ' })).toEqual({ raw: 'المعادي', governorate: 'C', area: null, street: 'شارع ٩', building: null, floor: null, apartment: null, landmark: null })
  })

  it('knows when there is enough to deliver', () => {
    expect(addressLooksComplete(emptyAddress())).toBe(false)
    expect(addressLooksComplete({ ...emptyAddress(), governorate: 'C', street: 'شارع ٩' })).toBe(true)
    expect(addressLooksComplete({ ...emptyAddress(), raw: 'المعادي، شارع ٩، عمارة ١٤' })).toBe(true)
  })
})

describe('itemsSummary', () => {
  it('counts pieces and lists the first titles', () => {
    expect(itemsSummary([{ title: 'A', quantity: 2 }, { title: 'B', quantity: 1 }, { title: 'C', quantity: 1 }])).toEqual({ titles: ['A', 'B'], more: 1, count: 4 })
  })
})
