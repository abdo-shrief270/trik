export const PLATFORMS = ['whatsapp', 'shopify', 'easyorders', 'other'] as const
export const ORDER_BANDS = ['s', 'm', 'l', 'xl'] as const

export type Platform = (typeof PLATFORMS)[number]
export type OrderBand = (typeof ORDER_BANDS)[number]

export interface WaitlistForm {
  name: string
  store: string
  email: string
  phone: string
  platform: Platform | ''
  orders: OrderBand | ''
  consent: boolean
}

export type WaitlistField = keyof WaitlistForm
export type WaitlistError = 'required' | 'email' | 'phone' | 'consent'
export type WaitlistErrors = Partial<Record<WaitlistField, WaitlistError>>

export const emptyWaitlistForm = (): WaitlistForm => ({
  name: '',
  store: '',
  email: '',
  phone: '',
  platform: '',
  orders: '',
  consent: false,
})

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

/** Turns what people type (spaces, dashes, Arabic digits, 00 prefix) into E.164, or null. */
export function normalisePhone(raw: string): string | null {
  const western = raw.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
  let digits = western.replace(/[\s\-().‎‏]/g, '')
  if (digits.startsWith('00')) digits = `+${digits.slice(2)}`
  // An Egyptian mobile typed locally (01x xxxx xxxx).
  if (/^01[0125]\d{8}$/.test(digits)) digits = `+20${digits.slice(1)}`
  return /^\+[1-9]\d{7,14}$/.test(digits) ? digits : null
}

export function validateWaitlist(form: WaitlistForm): WaitlistErrors {
  const errors: WaitlistErrors = {}
  if (!form.name.trim()) errors.name = 'required'
  if (!form.store.trim()) errors.store = 'required'
  if (!form.email.trim()) errors.email = 'required'
  else if (!EMAIL.test(form.email.trim())) errors.email = 'email'
  if (!form.phone.trim()) errors.phone = 'required'
  else if (!normalisePhone(form.phone)) errors.phone = 'phone'
  if (!form.platform) errors.platform = 'required'
  if (!form.orders) errors.orders = 'required'
  if (!form.consent) errors.consent = 'consent'
  return errors
}

/** The JSON body the API receives. */
export function waitlistPayload(form: WaitlistForm, locale: string) {
  return {
    name: form.name.trim(),
    store_name: form.store.trim(),
    email: form.email.trim().toLowerCase(),
    phone: normalisePhone(form.phone),
    platform: form.platform,
    monthly_orders: form.orders,
    locale,
    consent: form.consent,
  }
}
