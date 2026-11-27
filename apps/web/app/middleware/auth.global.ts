/**
 * Every page needs a signed-in user unless it says otherwise:
 *   definePageMeta({ auth: 'guest' })   sign-in, sign-up…: signed-in users go home
 *   definePageMeta({ auth: 'public' })  anyone (e.g. /verified, opened from an email)
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const session = useSessionStore()
  if (!session.loaded) {
    try {
      await session.load()
    } catch {
      // The API is unreachable: let the page render; it shows the error itself.
    }
  }

  const mode = to.meta.auth ?? 'required'
  if (mode === 'public') return
  if (mode === 'guest') return session.user ? navigateTo(safeNext(to.query.next)) : undefined
  if (!session.user) return navigateTo({ path: '/sign-in', query: to.fullPath === '/' ? {} : { next: to.fullPath } })
})
