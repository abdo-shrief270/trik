import { describe, expect, it } from 'vitest'
import { whatsappLink } from '../../app/utils/whatsapp'

describe('whatsappLink', () => {
  it('builds a click-to-chat link with the message encoded', () => {
    expect(whatsappLink('201012345678', 'أهلًا تريك & co')).toBe(
      `https://wa.me/201012345678?text=${encodeURIComponent('أهلًا تريك & co')}`,
    )
  })

  it('accepts the number as a number (Nuxt parses an all-digit env value into one)', () => {
    expect(whatsappLink(201012345678)).toBe('https://wa.me/201012345678')
  })

  it('accepts the number with +, spaces and dashes', () => {
    expect(whatsappLink('+20 101-234-5678')).toBe('https://wa.me/201012345678')
  })

  it.each(['', undefined, '0101234567', '12', 'not a number', '+0123456789'])('returns null for %s', (n) => {
    expect(whatsappLink(n, 'x')).toBeNull()
  })
})
