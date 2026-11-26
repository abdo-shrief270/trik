# ADR 0012: Organizations above workspaces

- **Status:** Accepted
- **Date:** 2026-11-26
- **Related:** #290 (multiple workspaces), #16 (agencies and multi-brand), #17 (plans and billing), #323–#326 (partners), #319 (SSO and IP rules)

## Context

Many COD sellers run several brands or stores: a fashion store and a cosmetics store, or one brand per country. Today a user can own up to 10 workspaces and switch between them, but each workspace stands alone:

- no shared billing;
- no way to give a manager access to all brands at once;
- no report across brands;
- no shared assets (templates, quick replies).

The workspace is the right tenant boundary, and it stays that way: numbers, inbox, contacts, orders and RLS all key on `workspace_id`. What's missing is a level **above** it.

## Decision

### 1. Model

```
organization ──< workspace ──< (numbers, contacts, orders, subscription … keyed by workspace_id, RLS)
     │                 └──< workspace_member (user, workspace role)   ← the only source of access
     ├──< organization_member (user, org role)                         ← manages the organization
     ├──  billing contact, invoices view
     └──  later: partner links (an agency org manages client orgs)
```

- **Every workspace belongs to exactly one organization.** Sign-up creates both: "Mona's organization" plus the "Mona Store" workspace. A one-brand seller never sees the word "organization". The UI only shows it once a second workspace exists. One code path, no "workspace without organization" special case.
- `workspaces.organization_id` is required. It can change later through an audited transfer (phase 2).
- `workspaces.owner_id` is replaced by the organization's ownership.

### 2. Access: organization roles manage the organization, workspace roles give access

Workspace access is **only** through workspace membership (decided: no automatic access).

| Organization role | What it gives |
|---|---|
| **Owner** | Everything at organization level: billing for every workspace, organization members, creating and deleting workspaces, transfers |
| **Admin** | Organization members, creating workspaces, adding people to workspaces. **No access to a workspace's data unless they are a member of it** |
| **Billing** | Invoices and payment methods of the organization's workspaces. No customer data |
| **Member** | Nothing at organization level. Appears in the organization's people list |

- Workspace roles (owner/admin/agent/custom, #28) stay exactly as they are and are the **only** source of grants inside a workspace.
- Organization permissions (`organization.members.manage`, `organization.workspaces.create`, `organization.billing.manage`, …) are a separate catalogue, checked with `X-Organization-Id`.
- An organization owner or admin *can* add people, themselves included, to a workspace. Every such change is written to both audit logs and shown to the workspace's admins, so access is always deliberate and visible.
- The grant ceiling (F-01) also applies here: nobody assigns more than they have.

### 2a. Switching between workspaces (like Slack)

- One sign-in per person, never one per workspace. A user who is an agent in three of an organization's brands switches with one click or tap, and only sees the brands they belong to.
- `GET /v1/auth/me` returns the user's organizations, each with **only** their workspaces, plus the role in each and the last-used workspace.
- **Badges per workspace**: unread conversations and conversations assigned to me, so an agent knows where to go. Delivered over the user's real-time channel (Centrifugo `user:{id}`) and as counts in `/me`.
- Deep links and push notifications carry the workspace id. Tapping a notification from brand B switches to brand B.
- Permissions are re-evaluated per workspace on every request (already the case). Switching never needs a new token.
- The last-used workspace per device is remembered (client side), with a server fallback.

### 3. Data isolation: unchanged for writes, one controlled widening for reads

- All writes stay in **one** workspace (`app.workspace_id`, `BelongsToWorkspace`), as today.
- **Organization reports** (orders across brands, confirmation rate per brand) run in a read-only **organization context**:
  - `TenantContext::runForOrganization($org)` sets `app.workspace_ids` to the workspaces **this user is a member of** in that organization;
  - the RLS **select** policy matches `workspace_id = any(app.workspace_ids)`;
  - the **insert/update/delete** policies keep matching only the single `app.workspace_id`.

  So cross-workspace writes are impossible even in organization context, and reading still never reaches another organization.
- **Split the policy now**, before there is production data: a select policy and a write policy per tenant table, a migration-only change today.

### 4. What is shared at organization level and what stays per workspace

| Organization level | Per workspace (unchanged) |
|---|---|
| People list and organization roles; SSO and IP rules (#319) | Workspace memberships and roles (the only source of access) |
| Consolidated invoices view and one billing contact; payment methods the owner may reuse | **Plan, subscription and order bundle per workspace** (decided). Each brand is billed on its own plan |
| Asset library: templates, quick replies (#324), copied into a workspace on use | WhatsApp numbers and Meta billing per WABA (ADR 0003) |
| Audit log (organization events) | Contacts, conversations, orders, campaigns, store connections, automations |
| Consolidated reports (read-only organization context, only the workspaces the user belongs to) | **Refusal history and risk data**: never shared across brands (decided) |

### 5. API

- New header `X-Organization-Id` for organization endpoints, under `/v1/organization/…`. Members must belong to the organization.
- `GET /v1/auth/me` returns organizations with their workspaces (for the switcher).
- `POST /v1/organization/workspaces` creates a workspace in the organization (it starts on its own plan or trial). The per-user limit of 10 owned workspaces becomes a limit per organization.
- `GET/POST/DELETE /v1/organization/workspaces/{id}/members` lets organization owners and admins add or remove people in a workspace (audited).
- Workspace endpoints are unchanged (`X-Workspace-Id`).
- Everything goes into `trik.v1.yaml` first (contract test).

### 6. Agencies later, without redesign

An agency is an organization too. A **partner link** (`organization_partners`: agency org → client org, scopes, status) gives the agency's staff delegated access to the client's workspaces. The client can revoke it at any time, and every action is audited as "agency X acting".

This builds on the same union-of-grants gate. Planned for the Scale phase (#323–#326).

## Phasing

| Phase | Milestone | Scope |
|---|---|---|
| **0 · Foundation** ✅ #366 | MVP · Pilot | `organizations` + `organizations_members`; `workspaces.organization_id` required; sign-up creates both; select/write RLS policy split; `/me` grouped by organization; switching between workspaces you belong to |
| **1 · Teams across brands** (roles, people, audit ✅ #367) | M1 (with team inbox) | Organization roles and permission catalogue; organization people list; owners/admins add people to workspaces (audited); create workspaces in an organization; per-workspace badges (unread, assigned to me) in `/me` and real time; switcher in web and mobile; notifications that switch workspace |
| **2 · Billing and group features** | M3 → M7 | Per-workspace plans with a consolidated invoices view at organization level (M3); organization-context reports for the workspaces you belong to; shared asset library; organization audit log; workspace transfer between organizations (M7, #290) |
| **3 · Partners** | M8 (#323–#326) | Partner links, delegated access, partner console, commission; SSO and IP rules at organization level (#319) |

## Consequences

- One extra level in the access check: the gate reads the organization membership along with the workspace membership (cached per request).
- Phase 0 is cheap now and expensive after launch, so it goes into the pilot.
- Billing stays per workspace (each brand on its own plan). The organization adds a consolidated view and shared billing contact, not a shared bundle.
- Reports across brands are possible without weakening isolation. Writes are never cross-workspace.

## Decisions taken (2026-11-26, Abdo)

1. **Billing:** a plan and order bundle per workspace, with an organization-level invoices view.
2. **Refusal history:** never shared across brands.
3. **Organization admins:** access only the workspaces they are members of.
4. **Switching:** a person in several workspaces of an organization switches between them with one sign-in, with badges per workspace (like Slack).
