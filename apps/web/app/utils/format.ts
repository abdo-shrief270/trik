/** Display helpers shared by screens. Western digits in both languages (as the brand book does). */
const loc = (locale: string) => (locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB')

/** "201012345678" → "+20 101 234 5678" (Egyptian mobiles grouped; others just get the +). */
export function formatPhone(digits: string | null | undefined): string {
  if (!digits) return '—'
  const d = digits.replace(/\D/g, '')
  const eg = d.match(/^20(1\d)(\d{4})(\d{4})$/)
  return eg ? `+20 ${eg[1]} ${eg[2]} ${eg[3]}` : `+${d}`
}

/** 10:44 today, "Tue" this week, "28 Nov" before that. */
export function shortTime(iso: string | null | undefined, locale: string, now = new Date()): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const sameDay = d.toDateString() === now.toDateString()
  const days = (now.getTime() - d.getTime()) / 86_400_000
  const opts: Intl.DateTimeFormatOptions = sameDay ? { hour: '2-digit', minute: '2-digit' } : days < 6 ? { weekday: 'short' } : { day: 'numeric', month: 'short' }
  return new Intl.DateTimeFormat(loc(locale), opts).format(d)
}

export function clockTime(iso: string | null | undefined, locale: string): string {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat(loc(locale), { hour: '2-digit', minute: '2-digit' }).format(d)
}

/** "Today", "Yesterday" or the date, for day separators (day = YYYY-MM-DD, local). */
export function dayLabel(day: string, locale: string, labels: { today: string; yesterday: string }, now = new Date()): string {
  const [y, m, d] = day.split('-').map(Number)
  if (!y || !m || !d) return ''
  const date = new Date(y, m - 1, d)
  const diff = Math.round((new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime() - date.getTime()) / 86_400_000)
  if (diff === 0) return labels.today
  if (diff === 1) return labels.yesterday
  return new Intl.DateTimeFormat(loc(locale), { weekday: 'long', day: 'numeric', month: 'long' }).format(date)
}
