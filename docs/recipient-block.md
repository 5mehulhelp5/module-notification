# Do not contact (recipient block) – design

Status: built. Start with the [README](../README.md).

**Goal:** a recipient stops all **marketing** notifications to their address with one click. The block ends by itself
once nobody has tried the address for a while. Service messages (orders, payments) are never blocked.

Shown to people as **Do not contact**; in code `RecipientBlockInterface` and `ck_notification_recipient_block`.

## Why

Anyone can type any address at checkout. A script can create many guest carts with one victim's address from fresh
sessions. The cooldown limits that to one reminder a week, but unsubscribing removes only one subscription (opt-in:
one consent; opt-out: no link at all), so the series goes on. The block works per recipient, whatever cart, browser
or consent the next sign-up comes from.

## Data: `ck_notification_recipient_block`

| Column | Content |
|---|---|
| `recipient_hash` | keyed hash of channel + normalized recipient, like the cooldown – never the address |
| `blocked_at` | when it was blocked |
| `last_attempt_at` | the last sign-up attempt for this recipient (starts as `blocked_at`) |

Global across stores, like the cooldown.

## Blocking

- Every marketing email gets `block_url`: *"Didn't start this? Stop these emails to this address."*
- The link is signed (HMAC with Magento's key) for the recipient hash.
- It opens a confirmation page; only its **POST** blocks, so email scanners that open links block nothing.
- On confirm: the row is written, every subscription for that recipient is deleted, and the page offers **Undo**
  (*"Changed your mind? Turn reminders back on."*).

## While blocked

- **Subscribe** (guest or customer): not stored, with the same response as a success so the list cannot be probed;
  `last_attempt_at` is set to now, which restarts the timer.
- **Sender:** `Delivery` gets a `marketing` flag (default `true`). Marketing deliveries to a blocked recipient are
  dropped with the status `BLOCKED`; service messages (`marketing: false`) are always sent.

## Ending the block

- **Automatically:** a nightly cron deletes blocks whose `last_attempt_at` is older than *N* days (default 90). An
  attacker who keeps trying keeps the block alive; once they stop, it lifts by itself.
- **Undo** on the page right after blocking.

## Interfaces and settings

- `Api\RecipientBlockInterface`: `isBlocked()`, `block()`, `unblock()`, `touch()` (restart the timer),
  `getBlockUrl()`. Replaceable with a di.xml preference.
- **Stores > Configuration > CommerceKings > Notifications**: *Do not contact link in emails* (default Yes),
  *Do not contact lifts after (days without attempts)* (default 90).

## Where it lives

| Module | Changes |
|---|---|
| Notification | table, interface and model, silent subscribe in `SubscriptionManagement`, `marketing` in `Delivery` and `Sender`, a storefront route (block page with Undo), cleanup cron, settings, docs |
| Abandoned cart | `block_url` in the reminder template, passed by `ReminderData` |
| Consent, consent bridge, others | none – the block sits on top of consent and wins |

## Edge cases

- A scanner opening the link: nothing happens, only the POST acts.
- A forged or altered link: "Link expired", nothing changes.
- A logged-in customer's address can be blocked too; the account does not prove the email.

## Tests

- Unit: hash and signature, silent subscribe restarting the timer, sender drops marketing and passes service
  messages, cron deletes only quiet blocks.
- End-to-end on a sandbox: 3 attacker carts → block → no more email → a new attempt restarts the timer → 90 quiet
  days (simulated) → cron lifts the block → the next cart is reminded; plus Undo.

## Later

- Bounce and spam-complaint feedback from providers, blocking a recipient automatically.
