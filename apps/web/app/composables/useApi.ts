import createClient, { type Middleware } from 'openapi-fetch'
import type { paths } from '~/types/api'

/**
 * The typed API client (types generated from packages/contracts/openapi/trik.v1.yaml: `pnpm api:types`).
 *
 * - Session cookie (Sanctum, `credentials: include`); on writes the XSRF token is fetched once and sent back.
 * - The current workspace goes in X-Workspace-Id; writes carry an Idempotency-Key.
 * - `call(api.GET('/auth/me'))` returns the data or throws one ApiError (problem+json, network, anything).
 */
type Client = ReturnType<typeof createClient<paths>>
let client: Client | null = null

const SAFE = new Set(['GET', 'HEAD', 'OPTIONS'])

function cookie(name: string): string | null {
  const m = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return m ? decodeURIComponent(m[1]!) : null
}

export function useApi(): Client {
  if (client) return client

  const base = useRuntimeConfig().public.apiBase.replace(/\/$/, '')
  const origin = new URL(base).origin
  let csrf: Promise<unknown> | null = null

  const auth: Middleware = {
    async onRequest({ request }) {
      if (!SAFE.has(request.method)) {
        if (!cookie('XSRF-TOKEN')) {
          csrf ??= fetch(`${origin}/sanctum/csrf-cookie`, { credentials: 'include' }).finally(() => (csrf = null))
          await csrf
        }
        const token = cookie('XSRF-TOKEN')
        if (token) request.headers.set('X-XSRF-TOKEN', token)
        if (!request.headers.has('Idempotency-Key')) request.headers.set('Idempotency-Key', crypto.randomUUID())
      }
      const workspace = useSessionStore().workspaceId
      if (workspace && !request.headers.has('X-Workspace-Id')) request.headers.set('X-Workspace-Id', workspace)
      return request
    },
  }

  client = createClient<paths>({ baseUrl: base, credentials: 'include', headers: { Accept: 'application/json' } })
  client.use(auth)
  return client
}

/**
 * The workspace header the contract requires on workspace endpoints:
 * `api.GET('/orders', { params: { ...inWorkspace(), query: {...} } })`.
 */
export function inWorkspace(): { header: { 'X-Workspace-Id': string } } {
  const id = useSessionStore().workspaceId
  if (!id) throw new ApiError({ status: 400, code: 'workspace-header-missing' })
  return { header: { 'X-Workspace-Id': id } }
}

/**
 * Unwraps an openapi-fetch result. A signed-out 401 resets the session and sends the user to sign in
 * (unless the caller handles it: `{ quiet401: true }`).
 */
export async function call<T>(
  request: Promise<{ data?: T; error?: unknown; response: Response }>,
  opts: { quiet401?: boolean } = {},
): Promise<T> {
  let res: { data?: T; error?: unknown; response: Response }
  try {
    res = await request
  } catch {
    throw new ApiError(toProblem(0, null))
  }

  if (res.response.ok) return res.data as T

  const problem = toProblem(res.response.status, res.error)
  if (problem.status === 401 && !opts.quiet401) {
    const session = useSessionStore()
    if (session.user) {
      session.reset()
      await navigateTo({ path: '/sign-in', query: { next: useRoute().fullPath } })
    }
  }
  throw new ApiError(problem)
}
