/**
 * The API answers every error with RFC 9457 problem+json (App\Support\Http\Problem): a stable `code`, and
 * `errors` per field on 422. This turns any failure into one shape the UI can switch on, and picks the
 * message key to show in the visitor's language. Server text is English; we show our own copy.
 */
export interface ApiProblem {
  status: number
  code: string
  detail?: string
  errors?: Record<string, string[]>
  requestId?: string
}

export class ApiError extends Error {
  readonly problem: ApiProblem

  constructor(problem: ApiProblem) {
    super(problem.detail ?? problem.code)
    this.name = 'ApiError'
    this.problem = problem
  }

  get status() {
    return this.problem.status
  }

  get code() {
    return this.problem.code
  }

  /** The first server message for a field (422), or undefined. */
  field(name: string): string | undefined {
    return this.problem.errors?.[name]?.[0]
  }
}

/** Builds the problem from a failed response's status and parsed body (anything, even HTML from a proxy). */
export function toProblem(status: number, body: unknown): ApiProblem {
  if (body && typeof body === 'object' && 'code' in body && typeof (body as { code: unknown }).code === 'string') {
    const b = body as { code: string; detail?: unknown; errors?: unknown; request_id?: unknown }
    return {
      status,
      code: b.code,
      detail: typeof b.detail === 'string' ? b.detail : undefined,
      errors: isErrorMap(b.errors) ? b.errors : undefined,
      requestId: typeof b.request_id === 'string' ? b.request_id : undefined,
    }
  }

  return { status, code: status === 0 ? 'network' : status >= 500 ? 'server-error' : `http-${status}` }
}

function isErrorMap(v: unknown): v is Record<string, string[]> {
  return !!v && typeof v === 'object' && Object.values(v).every((x) => Array.isArray(x) && x.every((s) => typeof s === 'string'))
}

/** Problem codes the UI has its own copy for (i18n: errors.<code>). Everything else falls back by status. */
const KNOWN = new Set([
  'network', 'server-error', 'unauthenticated', 'forbidden', 'not-found', 'rate-limited', 'validation-failed',
  'email-not-verified', 'conflict', 'unavailable',
  // team (Workspaces module)
  'owner-only', 'beyond-own-permissions', 'already-a-member', 'last-owner', 'cannot-change-own-role', 'invitation-limit',
  'invitation-closed',
])

/** The i18n key for a toast or form-level message. */
export function problemMessageKey(p: ApiProblem): string {
  if (KNOWN.has(p.code)) return `errors.${p.code}`
  if (p.status === 0) return 'errors.network'
  if (p.status === 401) return 'errors.unauthenticated'
  if (p.status === 403) return 'errors.forbidden'
  if (p.status === 404) return 'errors.not-found'
  if (p.status === 429) return 'errors.rate-limited'
  if (p.status >= 500) return 'errors.server-error'
  return 'errors.generic'
}

/**
 * Sign-in failures come back as a 422 on `email` with Laravel's English text (lang/en/auth.php): show our own
 * copy for the two we know, so Arabic users never see English.
 */
export function signInFailureKey(p: ApiProblem): string {
  const msg = p.errors?.email?.[0] ?? ''
  if (/too many/i.test(msg)) return 'auth.errors.throttled'
  if (p.code === 'validation-failed' && p.errors?.email) return 'auth.errors.failed'
  return problemMessageKey(p)
}
