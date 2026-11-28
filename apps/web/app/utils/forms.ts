/** Client-side checks that mirror the API's rules (Identity RegisterRequest / reset), for instant feedback. */
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

export type FieldError = 'required' | 'email' | 'passwordShort' | 'passwordWeak' | 'passwordMismatch' | 'tooLong'

export function checkEmail(v: string): FieldError | null {
  if (!v.trim()) return 'required'
  return EMAIL.test(v.trim()) ? null : 'email'
}

/** The API's rule: at least 10 characters, with letters and numbers. */
export function checkNewPassword(v: string): FieldError | null {
  if (!v) return 'required'
  if (v.length < 10) return 'passwordShort'
  if (!/\p{L}/u.test(v) || !/\d/.test(v)) return 'passwordWeak'
  return null
}

export function checkName(v: string, max = 120): FieldError | null {
  if (!v.trim()) return 'required'
  return v.trim().length > max ? 'tooLong' : null
}

/**
 * The message for a field after a 422: our own copy for the server messages we recognise (they are English),
 * so Arabic users read Arabic. Unknown ones fall back to a generic "check this field".
 */
export function serverFieldKey(message: string | undefined): string | null {
  if (!message) return null
  if (/already been taken/i.test(message)) return 'forms.emailTaken'
  if (/confirmation does not match/i.test(message)) return 'forms.passwordMismatch'
  if (/at least \d+ characters/i.test(message)) return 'forms.passwordShort'
  if (/must contain at least one (letter|number)/i.test(message)) return 'forms.passwordWeak'
  if (/valid email/i.test(message)) return 'forms.email'
  if (/is required/i.test(message)) return 'forms.required'
  return 'forms.invalid'
}
