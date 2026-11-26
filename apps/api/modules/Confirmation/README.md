# Confirmation

Confirmation engine, ladder (WhatsApp, reminder, call, SMS), call queue, auto-cancel.

**Phase:** Launch → Growth  ·  **Table prefix:** `confirmation_`

## How a COD order gets confirmed (#106)

1. **New order.** Every new **COD** order in a workspace with confirmation on starts a run in
   `confirmation_runs` (one per order). The template goes out on the queue within seconds, and the
   order moves to `confirming`.
2. **The template** (approved in the seller's WABA) gets:
   - `{{1}}` the customer name, `{{2}}` the order number, `{{3}}` the amount due on delivery (`950 ج.م`);
   - three **quick-reply buttons in this order: Confirm, Change, Cancel**. Trik sets each button's payload
     to `trik:c1:<action>:<run id>`, so a reply maps to exactly one order.
3. **A button reply.** Handled on the queue, never inside the webhook drain:
   - **Confirm** confirms the order and emits `order.confirmed.v1` with channel `whatsapp`;
   - **Cancel** cancels it;
   - **Change** escalates to a person.
   - A short reply (`confirmed_reply`, `cancelled_reply`, `change_reply`, Arabic defaults) goes back.
   - Idempotent: a second tap does nothing.
   - Only the order's own customer counts: a reply from another number is ignored and logged.
   - A flagged address turns Confirm into an escalation.
4. **No reply.**
   - After `reminder_after_minutes`, a reminder (`reminder_template_name`, or the same template), up to
     `max_reminders` times.
   - Then, after `escalate_after_minutes`, the run is **escalated** (`confirmation.escalated` in real time):
     a person calls. A Confirm pressed after that still counts.
5. **Settled elsewhere.** If the team or the store settles the order first, the run stops.

Every step is on the order's timeline: `confirmation_sent`, `confirmation_reminder`,
`confirmation_reply`, `confirmation_escalated`, `confirmation_failed`. Each sent message records its
`message_id`, so its cost follows from the message's pricing webhook (cost import: #330).

`confirmation:tick` runs every minute and queues the due runs (partial index on `next_action_at`, ids
only, then one unique job per run inside its workspace).

Suggested template (category *utility*, Arabic):

> أهلاً {{1}} 👋 طلبك رقم {{2}} وصلنا، والمطلوب عند الاستلام {{3}}. تأكد الطلب؟
> Buttons: «تأكيد» · «تعديل» · «إلغاء»

## Public API (Application layer)

| Contract | Purpose |
|---|---|
| `ConfirmationEngine` | start, advance (send / remind / escalate), reply, settled |

## Events

| Emits | Listens to |
|---|---|
| via Orders: `OrderChanged`, `order.confirmed.v1` (channel `whatsapp`) | `Orders\OrderChanged` (start a run / stop it) |
| real time: `confirmation.escalated` on `inbox:{workspace}` | `WhatsApp\MessageReceived` (button replies) |

## HTTP API

| Method | Path | Permission |
|---|---|---|
| GET / PUT | `/v1/confirmation/settings` | `confirmation.settings.view` / `confirmation.settings.manage` |
| GET | `/v1/orders/{id}/confirmation` | `orders.orders.view` |

## Not yet

- Writing the confirmed / cancelled status back to the store (with the connectors, #332 #117).
- The call queue and SMS steps of the ladder (M5); the confirmation board (#107).
