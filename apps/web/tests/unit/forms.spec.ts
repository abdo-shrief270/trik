import { describe, expect, it } from 'vitest'
import { checkEmail, checkName, checkNewPassword, serverFieldKey } from '../../app/utils/forms'
import { initials, safeNext, tint } from '../../app/utils/nav'

describe('safeNext (no open redirects after sign-in)', () => {
  it.each([
    ['/inbox', '/inbox'],
    ['/orders?status=new#top', '/orders?status=new#top'],
    ['https://evil.example', '/'],
    ['//evil.example/x', '/'],
    ['/\\evil.example', '/'],
    ['javascript:alert(1)', '/'],
    ['/sign-in?next=/x', '/'],
    ['/reset-password', '/'],
    [undefined, '/'],
    [['/a', '/b'], '/'],
    ['/a\nb', '/'],
  ])('%j → %s', (input, out) => expect(safeNext(input)).toBe(out))
})

describe('form checks mirror the API rules', () => {
  it('passwords: 10+ characters with letters and numbers (Arabic letters count)', () => {
    expect(checkNewPassword('')).toBe('required')
    expect(checkNewPassword('abc12')).toBe('passwordShort')
    expect(checkNewPassword('abcdefghijk')).toBe('passwordWeak')
    expect(checkNewPassword('1234567890')).toBe('passwordWeak')
    expect(checkNewPassword('secret-pass-123')).toBeNull()
    expect(checkNewPassword('كلمةسر123456')).toBeNull()
  })

  it('emails and names', () => {
    expect(checkEmail(' mona@example.com ')).toBeNull()
    expect(checkEmail('mona@')).toBe('email')
    expect(checkName('   ')).toBe('required')
    expect(checkName('x'.repeat(121))).toBe('tooLong')
  })

  it('maps the API English messages to our copy', () => {
    expect(serverFieldKey('The email has already been taken.')).toBe('forms.emailTaken')
    expect(serverFieldKey('The password field must be at least 10 characters.')).toBe('forms.passwordShort')
    expect(serverFieldKey('Something new')).toBe('forms.invalid')
    expect(serverFieldKey(undefined)).toBeNull()
  })
})

describe('avatars', () => {
  it('initials work for English and Arabic names', () => {
    expect(initials('Mona Adel')).toBe('MA')
    expect(initials('منى عادل')).toBe('من'.slice(0, 1) + 'ع')
    expect(initials('  ')).toBe('?')
  })

  it('the same id always gets the same tint', () => {
    expect(tint('abc')).toBe(tint('abc'))
  })
})
