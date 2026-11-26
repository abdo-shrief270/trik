# Workspaces

Seller workspaces (tenants), multiple brands, agencies and partner console.

**Phase:** Launch → Scale  ·  **Table prefix:** `workspaces_`

## Organizations (ADR 0012)

Every workspace belongs to one organization (`workspaces_organizations`). Sign-up creates both.

- Organization roles (`workspaces_organization_members`: owner, admin, billing, member) manage the organization.
- Workspace roles (`workspaces_members`) are the **only** source of access to a workspace's data.

## Public API (Application layer)

| Contract | Purpose |
|---|---|
| `CreateOrganization` | new organization with its owner |
| `CreateWorkspace::forUser()` | new workspace in the right organization (named, the one you manage, or a new one) |
| `WorkspaceDirectory` | a user's workspaces and organizations (only theirs), for the switcher |
| `OrganizationContext` | read-only view across the viewer's workspaces in one organization |
| `ChangeMemberRole`, `ManageRoles` | team rules and custom roles (grant ceiling) |

## Events

| Emits | Listens to |
|---|---|
| — | — |
