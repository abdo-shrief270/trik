# Contacts

Customer profiles, consent ledger, segments, activity timeline, deals.

**Phase:** Launch  ·  **Table prefix:** `contacts_`

Today: one contact per WhatsApp number (`contacts_contacts`, unique per workspace and phone, digits in wa_id
format), created as customers write in or as the business writes to them. A WhatsApp profile name fills an
empty name and never overwrites one the team set. Other channels (#81, #82) will add an identities table
that points to the same contact, so one customer keeps one conversation.

## Public API (Application layer)

| Contract | Purpose |
|---|---|
| `ContactDirectory::resolve(phones)` | find or create contacts in 2 queries, whatever the count |
| `ContactDirectory::find(ids)` | id, name and phone of contacts |

## Events

| Emits | Listens to |
|---|---|
| — | — |
