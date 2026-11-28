# Workspaces

Seller workspaces (tenants), multiple brands, agencies and partner console.

**Phase:** Launch → Scale  ·  **Table prefix:** `workspaces_`

## Organizations (ADR 0012)

Every workspace belongs to one organization (`workspaces_organizations`). Sign-up creates both.

- Organization roles (`workspaces_organization_members`: owner, admin, billing, member) manage the organization.
- Workspace roles (`workspaces_members`) are the **only** source of access to a workspace's data.
- Organization endpoints live under `/v1/organization/*` with the `X-Organization-Id` header. Their permissions (`organization.*`) come from the organization role and can never be put into a custom workspace role.
- Audit logs:
  - `workspaces_audit_events` is per workspace and under RLS; workspace admins read it at `/v1/workspace/audit`;
  - `workspaces_organization_audit_events` is per organization (`/v1/organization/audit`);
  - both are append-only for the app role.

## Public API (Application layer)

| Contract | Purpose |
|---|---|
| `CreateOrganization` | new organization with its owner |
| `CreateWorkspace::forUser()` | new workspace in the right organization (named, the one you manage, or a new one) |
| `WorkspaceDirectory` | a user's workspaces and organizations (only theirs), for the switcher |
| `OrganizationContext` | read-only view across the viewer's workspaces in one organization |
| `OrganizationTeam` | organization roles, leaving or removing, adding people to workspaces (rules + audit) |
| `AuditLog` | append-only workspace and organization audit events |
| `ChangeMemberRole`, `ManageRoles` | team rules and custom roles (grant ceiling) |
| `Invitations` | team invitations by email (#36): invite, withdraw, preview and accept a link, sign-up with one |

## Team invitations (#36)

- An owner or admin (`workspace.members.manage`, verified email) invites an address with a role. Nobody offers more than
  they have, and only owners invite owners. A queued, bilingual email carries a link (`{FRONTEND_URL}/invite/{token}`),
  which the API also returns once, so it can be shared on WhatsApp.
- The link works **once, for 7 days, only for the invited address**, and only while its sender can still manage the
  team. Inviting the same address again replaces the open invitation. Deleting a custom role withdraws the
  invitations that offered it.
- Accepting joins the workspace with the offered role and its organization as a `member`, and verifies the email (the
  link went to it). Signing up with `invitation` does the same without creating a workspace.
- `workspaces_invitations` has RLS; only the token's SHA-256 is stored, found by `workspaces_invitation_by_token()`
  (SECURITY DEFINER, returns ids only). Limits: 20 invitations per hour per person, 100 open per workspace.

## Events

| Emits | Listens to |
|---|---|
| — | — |
