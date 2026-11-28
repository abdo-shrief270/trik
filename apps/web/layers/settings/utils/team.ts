/**
 * Team settings logic, kept out of the page so it can be tested. The API enforces every rule again
 * (Modules\Workspaces\Application\Invitations, ChangeMemberRole); this only avoids offering what it would refuse.
 */
export type RoleName = 'owner' | 'admin' | 'agent' | 'custom'

export interface RoleChoice {
  /** "owner" | "admin" | "agent" | "custom:<uuid>" */
  value: string
  role: RoleName
  customRoleId: string | null
  label: string
}

/**
 * Roles someone may offer or give: an owner can make owners; everyone else can't. Custom roles are listed by name
 * (the API checks their permissions against yours).
 */
export function roleChoices(
  myRole: RoleName | undefined,
  customRoles: { id: string; name: string }[],
  label: (role: RoleName) => string,
): RoleChoice[] {
  const builtIn: RoleName[] = myRole === 'owner' ? ['agent', 'admin', 'owner'] : ['agent', 'admin']
  return [
    ...builtIn.map((role) => ({ value: role, role, customRoleId: null, label: label(role) })),
    ...customRoles.map((r) => ({
      value: `custom:${r.id}`,
      role: 'custom' as const,
      customRoleId: r.id,
      label: r.name,
    })),
  ]
}

/** The choice for a member's or invitation's current role. */
export function choiceValue(role: RoleName, customRoleId: string | null | undefined): string {
  return role === 'custom' && customRoleId ? `custom:${customRoleId}` : role
}

/** Splits a choice back into what the API takes. */
export function parseChoice(value: string): { role: RoleName; custom_role_id: string | null } {
  if (value.startsWith('custom:')) return { role: 'custom', custom_role_id: value.slice('custom:'.length) }
  return { role: value as RoleName, custom_role_id: null }
}

export type InvitationStatus = 'pending' | 'expired' | 'accepted' | 'revoked'

export function statusTone(status: InvitationStatus): 'neutral' | 'warning' | 'success' | 'danger' {
  return ({ pending: 'neutral', expired: 'warning', accepted: 'success', revoked: 'danger' } as const)[status]
}

/** A WhatsApp share link with the invitation (no number: the inviter picks the chat). */
export function whatsappShare(text: string, url: string): string {
  return `https://wa.me/?text=${encodeURIComponent(`${text}\n${url}`)}`
}

/** Can I change or remove this member? Mirrors the API: not myself (except leaving), owners only by owners. */
export function canManageMember(
  me: { id: string; role: RoleName | undefined; canManage: boolean },
  member: { user_id: string; role: RoleName },
): boolean {
  if (!me.canManage || member.user_id === me.id) return false
  return member.role !== 'owner' || me.role === 'owner'
}
