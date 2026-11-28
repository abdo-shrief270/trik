/** Pure helpers for the orders screens (tested in tests/unit/orders.spec.ts). Money is in minor units. */

export const ORDER_STATUSES = ['new', 'confirming', 'awaiting_deposit', 'confirmed', 'ready_to_ship', 'shipped', 'delivered', 'returned', 'cancelled'] as const
export type OrderStatus = (typeof ORDER_STATUSES)[number]
export const isOrderStatus = (v: unknown): v is OrderStatus => typeof v === 'string' && (ORDER_STATUSES as readonly string[]).includes(v)

/** "1,250.50" or "١٢٥٠" (Arabic digits typed on a phone keyboard) → 125050 piastres; null if it isn't a sum of money. */
export function parsePounds(input: string): number | null {
  const western = input.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[٫]/g, '.').replace(/[,،٬\s]/g, '')
  if (!/^\d+(\.\d{1,2})?$/.test(western)) return null
  return Math.round(Number(western) * 100)
}

export interface DraftItem { title: string; variant: string; quantity: string; price: string }

export function itemTotal(i: DraftItem): number {
  const q = Number.parseInt(i.quantity, 10)
  const p = parsePounds(i.price)
  return Number.isFinite(q) && q > 0 && p !== null ? q * p : 0
}

/** Subtotal, total and what's collected on delivery, as the API will compute them. */
export function draftTotals(items: DraftItem[], shipping: string, discount: string, method: 'cod' | 'prepaid') {
  const subtotal = items.reduce((n, i) => n + itemTotal(i), 0)
  const ship = parsePounds(shipping || '0') ?? 0
  const disc = parsePounds(discount || '0') ?? 0
  const total = Math.max(0, subtotal + ship - disc)
  return { subtotal, shipping: ship, discount: disc, total, cod: method === 'cod' ? total : 0 }
}

export type DraftErrors = Partial<Record<'phone' | 'items' | 'shipping' | 'discount' | `item.${number}.${'title' | 'quantity' | 'price'}`, string>>

/** Mirrors POST /orders: a phone, at least one item with a title, a whole quantity ≥ 1 and a price. */
export function validateDraft(d: { phone: string; items: DraftItem[]; shipping: string; discount: string }): DraftErrors {
  const e: DraftErrors = {}
  const digits = d.phone.replace(/[٠-٩]/g, (c) => String(c.charCodeAt(0) - 0x0660)).replace(/[^\d]/g, '')
  if (!digits) e.phone = 'required'
  else if (digits.length < 8 || digits.length > 15) e.phone = 'phone'
  if (!d.items.length) e.items = 'required'
  d.items.forEach((i, n) => {
    if (!i.title.trim()) e[`item.${n}.title`] = 'required'
    if (!/^\d+$/.test(i.quantity.trim()) || Number(i.quantity) < 1) e[`item.${n}.quantity`] = 'quantity'
    if (parsePounds(i.price) === null) e[`item.${n}.price`] = 'money'
  })
  if (d.shipping.trim() && parsePounds(d.shipping) === null) e.shipping = 'money'
  if (d.discount.trim() && parsePounds(d.discount) === null) e.discount = 'money'
  return e
}

export interface AddressForm { raw: string; governorate: string; area: string; street: string; building: string; floor: string; apartment: string; landmark: string }

export const emptyAddress = (): AddressForm => ({ raw: '', governorate: '', area: '', street: '', building: '', floor: '', apartment: '', landmark: '' })

/** Form → API body: trimmed, empty strings as null (so clearing a field clears it). */
export function addressBody(a: AddressForm) {
  const v = (s: string) => (s.trim() ? s.trim() : null)
  return { raw: v(a.raw), governorate: v(a.governorate), area: v(a.area), street: v(a.street), building: v(a.building), floor: v(a.floor), apartment: v(a.apartment), landmark: v(a.landmark) }
}

/** API address → form. */
export function addressForm(a: Partial<Record<keyof AddressForm, string | null>> | null | undefined): AddressForm {
  const f = emptyAddress()
  for (const k of Object.keys(f) as (keyof AddressForm)[]) f[k] = a?.[k] ?? ''
  return f
}

/** The fields the API's Address::isDeliverable() needs that are still empty (governorate, area, street). */
export function missingAddressFields(a: AddressForm): ('governorate' | 'area' | 'street')[] {
  return (['governorate', 'area', 'street'] as const).filter((k) => !a[k].trim())
}

/** Deliverable as the API sees it: governorate, area and street (PATCH /orders rejects less with address-incomplete). */
export function addressLooksComplete(a: AddressForm): boolean {
  return missingAddressFields(a).length === 0
}

/** "2 items: Linen dress, Cotton shirt" style summary for the list. */
export function itemsSummary(items: { title?: string; quantity?: number }[] | undefined, max = 2): { titles: string[]; more: number; count: number } {
  const list = items ?? []
  const count = list.reduce((n, i) => n + (i.quantity ?? 1), 0)
  return { titles: list.slice(0, max).map((i) => i.title ?? ''), more: Math.max(0, list.length - max), count }
}
