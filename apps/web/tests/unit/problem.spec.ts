import { describe, expect, it } from 'vitest'
import { ApiError, problemMessageKey, signInFailureKey, toProblem } from '../../app/utils/problem'

describe('toProblem', () => {
  it('reads RFC 9457 problem+json from the API', () => {
    expect(toProblem(422, { type: 'x', title: 'x', status: 422, code: 'validation-failed', detail: 'Bad', errors: { email: ['Taken'] }, request_id: 'r1' })).toEqual({
      status: 422, code: 'validation-failed', detail: 'Bad', errors: { email: ['Taken'] }, requestId: 'r1',
    })
  })

  it('copes with anything else (proxy HTML, empty body, no network)', () => {
    expect(toProblem(502, '<html>bad gateway</html>').code).toBe('server-error')
    expect(toProblem(0, null).code).toBe('network')
    expect(toProblem(418, undefined).code).toBe('http-418')
  })

  it('ignores a malformed errors map', () => {
    expect(toProblem(422, { code: 'validation-failed', errors: { email: 'not an array' } }).errors).toBeUndefined()
  })
})

describe('problemMessageKey', () => {
  it.each([
    [{ status: 0, code: 'network' }, 'errors.network'],
    [{ status: 503, code: 'unavailable' }, 'errors.unavailable'],
    [{ status: 403, code: 'owner-only' }, 'errors.forbidden'],
    [{ status: 404, code: 'member-not-found' }, 'errors.not-found'],
    [{ status: 429, code: 'whatever' }, 'errors.rate-limited'],
    [{ status: 500, code: 'boom' }, 'errors.server-error'],
    [{ status: 409, code: 'window-closed' }, 'errors.generic'],
  ])('%j → %s', (p, key) => expect(problemMessageKey(p)).toBe(key))
})

describe('signInFailureKey', () => {
  it('shows our own copy for wrong credentials and for the lockout', () => {
    expect(signInFailureKey({ status: 422, code: 'validation-failed', errors: { email: ['These credentials do not match our records.'] } })).toBe('auth.errors.failed')
    expect(signInFailureKey({ status: 422, code: 'validation-failed', errors: { email: ['Too many login attempts. Please try again in 58 seconds.'] } })).toBe('auth.errors.throttled')
    expect(signInFailureKey({ status: 0, code: 'network' })).toBe('errors.network')
  })
})

describe('ApiError', () => {
  it('exposes status, code and the first field message', () => {
    const e = new ApiError({ status: 422, code: 'validation-failed', errors: { name: ['Required', 'Also'] } })
    expect([e.status, e.code, e.field('name'), e.field('email')]).toEqual([422, 'validation-failed', 'Required', undefined])
    expect(e).toBeInstanceOf(Error)
  })
})
