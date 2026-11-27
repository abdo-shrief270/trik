/**
 * Where to go after signing in (?next=…). Only paths inside this app: anything else (another site, a
 * protocol-relative //evil.example, a backslash trick) falls back to home, so the link can't be used to send
 * people elsewhere after they sign in.
 */
export function safeNext(next: unknown, fallback = '/'): string {
  if (typeof next !== 'string' || next.length > 2000) return fallback
  if (!next.startsWith('/') || next.startsWith('//') || next.includes('\\') || /\p{Cc}/u.test(next)) return fallback
  if (/^\/(sign-in|sign-up|forgot-password|reset-password)(\/|\?|$)/.test(next)) return fallback
  return next
}

/** Initials for an avatar: first letters of the first two words (works for Arabic too). */
export function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean)
  return (words.slice(0, 2).map((w) => [...w][0]).join('') || '?').toUpperCase()
}

/** A stable tint per id, so the same person or workspace always gets the same colour. */
const TINTS = ['bg-indigo-soft text-indigo-strong', 'bg-[#F6E3D7] text-[#8A4B22]', 'bg-success-bg text-success', 'bg-warning-bg text-warning', 'bg-[#E8E6F0] text-ink']
export function tint(id: string): string {
  let h = 0
  for (const ch of id) h = (h * 31 + ch.charCodeAt(0)) >>> 0
  return TINTS[h % TINTS.length]!
}
