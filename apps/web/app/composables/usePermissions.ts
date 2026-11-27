/**
 * What the signed-in member may do in the current workspace (GET /workspace/permissions). Used to hide
 * actions they can't take; the API still checks every request.
 */
export function usePermissions() {
  const session = useSessionStore()
  const { data, status } = useAsyncData(
    () => `permissions:${session.workspaceId}`,
    async () => {
      const res = await call(useApi().GET('/workspace/permissions', { params: inWorkspace() }))
      return new Set(res.data?.permissions ?? [])
    },
    { watch: [() => session.workspaceId], default: () => new Set<string>() },
  )

  /** `can('inbox.conversations.reply')`. Wildcards like `orders.*` are expanded by the API. */
  const can = (permission: string) => data.value.has(permission)

  return { can, loaded: computed(() => status.value === 'success') }
}
