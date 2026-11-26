# Inbox

Conversations, assignment, internal notes, quick replies, the 24-hour window, snooze and search.

**Phase:** Launch  ·  **Table prefix:** `inbox_`  ·  **Feature flag:** `FEATURE_INBOX` (`all`, `none`, or workspace ids)

## One conversation per customer (#56)

`inbox_conversations` holds one row per customer per workspace, whatever number (or, later, channel) they
write to. Messages are not copied: they stay in the channel's log (`whatsapp_messages`), and the
conversation is the summary the inbox lists: last message, unread count, assignee, status, window.

The row is kept up to date by `RecordConversationActivity`, which listens to WhatsApp's `MessagesLogged`
**inside the transaction that writes the messages**, so the inbox can never miss a message or show one that
was rolled back. It is set-based: a batch of 500 webhook deliveries costs 2 contact queries and 1 upsert,
whatever the number of customers.

- A customer message opens (or reopens) the conversation and counts as unread.
- A reply from the business marks it read.
- Replies go out from the number the customer last wrote to (`number_id`).
- Order within a conversation: `created_at, id` (UUIDv7, assigned oldest first within a batch;
  batches of a workspace are drained one at a time).

## The messaging window (#60)

`window` on every conversation, computed by `Domain\MessagingWindow`:

| Field | Meaning |
|---|---|
| `open`, `closes_at` | free-form messages (text, media, buttons) are allowed until 24 hours after the customer's last message; after that, templates only. A free-form reply after that answers `409 window-closed`. |
| `entry: "ad"` | the customer came from a Click-to-WhatsApp ad or a Facebook Page button (`referral` in the webhook) |
| `free_until` | Meta's free entry point: answering such a customer within 24 hours makes everything free for 72 hours from the answer. It does **not** extend the 24-hour window. |

## Public API (Application layer)

| Contract | Purpose |
|---|---|
| `ConversationPresenter` | API shape of conversations (with contacts) |
| `ManageConversation` | assign, close/reopen, mark read (with real-time notification) |
| `Channels::inbox($workspaceId)` | real-time channel name |

## Events

| Emits | Listens to |
|---|---|
| real time on `inbox:{workspace}`: `conversation.updated` (id, status, assignee, unread, last message time), after commit | `WhatsApp\MessagesLogged` (in transaction) |

## HTTP API (`/v1/inbox`, signed in, `X-Workspace-Id`)

| Method | Path | Permission |
|---|---|---|
| GET | `/conversations?status=open\|closed\|all&assignee=me\|unassigned\|{id}&unread=1` | `inbox.conversations.view` |
| GET | `/conversations/{id}` | `inbox.conversations.view` |
| PATCH | `/conversations/{id}` (`status`, `assignee_id`) | `inbox.conversations.reply`; assigning others also `inbox.conversations.assign` |
| POST | `/conversations/{id}/read` | `inbox.conversations.view` |
| GET | `/conversations/{id}/messages` | `inbox.conversations.view` |
| POST | `/conversations/{id}/messages` (Idempotency-Key required) | `inbox.conversations.reply` |

Agents get view and reply; they can take an unassigned conversation or let go of their own.

## Not yet

- Per-workspace badges (#368), notes and mentions (#62), collision detection (#63), views (#57).
- Read receipts back to the customer (blue ticks) on `read`.
- Signed Centrifugo subscription tokens (#98): set `CENTRIFUGO_API_URL` / `CENTRIFUGO_API_KEY` to publish.
