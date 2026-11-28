import { defineStore } from 'pinia'
import type { components } from '~/types/api'

type User = components['schemas']['User']
type Workspace = components['schemas']['WorkspaceWithBadges']
type Organization = components['schemas']['OrganizationWithBadges']

const WORKSPACE_KEY = 'trik.workspace'

/** Who is signed in, their workspaces (with badges) and the one they're working in. Loaded from /auth/me. */
export const useSessionStore = defineStore('session', {
  state: () => ({
    user: null as User | null,
    workspaces: [] as Workspace[],
    organizations: [] as Organization[],
    workspaceId: null as string | null,
    loaded: false,
  }),

  getters: {
    workspace: (s): Workspace | null => s.workspaces.find((w) => w.id === s.workspaceId) ?? null,
    /** Unread across every workspace except the current one (the switcher's dot). */
    unreadElsewhere: (s): number => s.workspaces.filter((w) => w.id !== s.workspaceId).reduce((n, w) => n + (w.badges?.unread ?? 0), 0),
  },

  actions: {
    async load(): Promise<void> {
      try {
        const me = await call(useApi().GET('/auth/me'), { quiet401: true })
        this.user = me.user ?? null
        this.workspaces = me.workspaces ?? []
        this.organizations = me.organizations ?? []
        this.pickWorkspace()
      } catch (e) {
        if (!(e instanceof ApiError) || e.status !== 401) throw e
        this.reset()
      } finally {
        this.loaded = true
      }
    },

    async signIn(email: string, password: string): Promise<void> {
      await call(useApi().POST('/auth/login', { body: { email, password } }), { quiet401: true })
      await this.load()
    },

    async signUp(body: { name: string; email: string; password: string; password_confirmation: string; workspace_name: string }): Promise<void> {
      const res = await call(useApi().POST('/auth/register', { body }), { quiet401: true })
      if (res.workspace?.id) this.selectWorkspace(res.workspace.id)
      await this.load()
    },

    async signOut(): Promise<void> {
      try {
        await call(useApi().POST('/auth/logout'), { quiet401: true })
      } finally {
        this.reset()
        await navigateTo('/sign-in')
      }
    },

    async resendVerification(): Promise<void> {
      await call(useApi().POST('/auth/email/verification-notification'))
    },

    selectWorkspace(id: string): void {
      this.workspaceId = id
      localStorage.setItem(WORKSPACE_KEY, id)
    },

    /** The remembered workspace if it's still one of theirs, else the first. */
    pickWorkspace(): void {
      const remembered = this.workspaceId ?? localStorage.getItem(WORKSPACE_KEY)
      const id = this.workspaces.some((w) => w.id === remembered) ? remembered : (this.workspaces[0]?.id ?? null)
      if (id) this.selectWorkspace(id)
      else this.workspaceId = null
    },

    reset(): void {
      this.user = null
      this.workspaces = []
      this.organizations = []
      this.workspaceId = null
    },
  },
})
