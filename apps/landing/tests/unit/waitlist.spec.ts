import { describe, expect, it } from 'vitest'
import { emptyWaitlistForm, normalisePhone, validateWaitlist, waitlistPayload, type WaitlistForm } from '../../app/utils/waitlist'

const valid = (): WaitlistForm => ({
  name: 'Sara',
  store: 'Sara Store',
  email: 'Sara@Example.com ',
  phone: '+20 101 234 5678',
  platform: 'shopify',
  orders: 'm',
  consent: true,
})

describe('normalisePhone', () => {
  it.each([
    ['+20 101 234 5678', '+201012345678'],
    ['0020-101-234-5678', '+201012345678'],
    ['01012345678', '+201012345678'],
    ['٠١٠١٢٣٤٥٦٧٨', '+201012345678'],
    ['+966 50 123 4567', '+966501234567'],
    ['‎+20 (101) 234.5678', '+201012345678'],
  ])('%s → %s', (raw, e164) => expect(normalisePhone(raw)).toBe(e164))

  it.each(['12', '+0123456789', 'phone', '+2010123456789012345', '0101234567'])('rejects %s', (raw) => {
    expect(normalisePhone(raw)).toBeNull()
  })
})

describe('validateWaitlist', () => {
  it('passes a complete form', () => {
    expect(validateWaitlist(valid())).toEqual({})
  })

  it('flags every empty field', () => {
    expect(validateWaitlist(emptyWaitlistForm())).toEqual({
      name: 'required',
      store: 'required',
      email: 'required',
      phone: 'required',
      platform: 'required',
      orders: 'required',
      consent: 'consent',
    })
  })

  it('treats whitespace as empty and checks email and phone shape', () => {
    const f = { ...valid(), name: '   ', email: 'sara@', phone: '123' }
    expect(validateWaitlist(f)).toEqual({ name: 'required', email: 'email', phone: 'phone' })
  })
})

describe('waitlistPayload', () => {
  it('trims, lower-cases the email and sends the phone as E.164', () => {
    expect(waitlistPayload(valid(), 'ar')).toEqual({
      name: 'Sara',
      store_name: 'Sara Store',
      email: 'sara@example.com',
      phone: '+201012345678',
      platform: 'shopify',
      monthly_orders: 'm',
      locale: 'ar',
      consent: true,
    })
  })
})
