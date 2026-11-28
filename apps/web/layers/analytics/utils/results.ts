/** Formatting for the pilot results (tested in tests/unit/results.spec.ts). */

export type Metric = 'confirmation' | 'cancellation' | 'refusal'

/** For which metrics a drop is good news. */
const LOWER_IS_BETTER: Record<Metric, boolean> = { confirmation: false, cancellation: true, refusal: true }

const nf = (locale: string, digits: number) => new Intl.NumberFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', { maximumFractionDigits: digits, minimumFractionDigits: 0 })

/** 0.3333 → "33.3%" ("٣٣٫٣٪" in Arabic); null → "—" (nothing to measure yet). */
export function pct(v: number | null | undefined, locale = 'en'): string {
  if (v === null || v === undefined) return '—'
  return new Intl.NumberFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', { style: 'percent', maximumFractionDigits: 1 }).format(v)
}

/** A change in percentage points, and whether it is good news for this metric. */
export function change(points: number | null | undefined, metric: Metric, locale = 'en'): { text: string; tone: 'success' | 'danger' | 'neutral'; sign: 1 | -1 | 0 } {
  if (points === null || points === undefined) return { text: '—', tone: 'neutral', sign: 0 }
  const sign = points > 0 ? 1 : points < 0 ? -1 : 0
  const good = sign === 0 ? null : (sign < 0) === LOWER_IS_BETTER[metric]
  return {
    text: `${sign > 0 ? '+' : sign < 0 ? '−' : ''}${nf(locale, 1).format(Math.abs(points))}`,
    tone: good === null ? 'neutral' : good ? 'success' : 'danger',
    sign,
  }
}

/** 90 → "1 h 30 min", 12.5 → "13 min"; null → "—". Units come from the caller's i18n. */
export function duration(minutes: number | null | undefined, units: { h: string; min: string }, locale = 'en'): string {
  if (minutes === null || minutes === undefined) return '—'
  const total = Math.round(minutes)
  const h = nf(locale, 0).format(Math.floor(total / 60))
  const m = nf(locale, 0).format(total % 60)
  if (total < 60) return `${m} ${units.min}`
  return total % 60 === 0 ? `${h} ${units.h}` : `${h} ${units.h} ${m} ${units.min}`
}
