# Do not contact (recipient stop) – design

Status: built. Start with the [README](../README.md).

**Goal:** a recipient stops all **marketing** notifications to their address with one click. The stop ends by itself
once nobody has tried the address for a while. Service messages (orders, payments) are never stopped.

Shown to people as **Do not contact**; in code `StopListInterface` and `ck_notification_stop`.

## Why

Anyone can type any address at checkout. A script can create many guest carts with one victim's address from fresh
sessions. The cooldown limits that to one reminder a week, but unsubscribing removes only one subscription (opt-in:
one consent; opt-out: no link at all), so the series goes on. The stop works per recipient, whatever cart, browser
or consent the next sign-up comes from.

## Data: `ck_notification_stop`

| Column | Content |
|---|---|
| `recipient_hash` | keyed hash of channel + normalized recipient, like the cooldown – never the address |
| `reason` | `link`, `reply` (STOP reply or button) or `undeliverable` (`StopListInterface::REASON_*`); the strongest is kept |
| `stopped_at` | when it was stopped |
| `last_attempt_at` | the last sign-up attempt for this recipient (starts as `stopped_at`) |

Global, like the cooldown: a stop applies in every website and store, whichever one it came from.

## Stopping

- Every marketing email gets `stop_url`: *"Didn't start this? Stop these emails to this address."*
- The link carries the recipient encrypted with Magento's key (`r`) and an HMAC signature over it (`s`); no
  address appears in the URL.
- It opens a confirmation page; only its **POST** stops, so email scanners that open links stop nothing.
- On confirm: the row is written, the recipient's stoppable subscriptions on that channel are deleted (service types
  such as an order message keep theirs), and the page offers **Undo**
  (*"Changed your mind? Turn reminders back on."*).

## While stopped

- **Subscribe** (guest or customer): not stored, with the same response as a success so the list cannot be probed;
  `last_attempt_at` is set to now, which restarts the timer.
- **Sender:** `Delivery` gets a `stoppable` flag (default `true`; the feature decides). Stoppable deliveries to a
  stopped recipient are dropped with the status `STOPPED`; the others (`stoppable: false`, e.g. order messages) are
  always sent.

## Ending the stop

Each stop keeps the strongest reason it was given (reply, then link, then undeliverable):

| Reason | Made by | Consents | Ends |
|---|---|---|---|
| `REASON_LINK` | the stop link or its one-click POST | withdrawn | by the cron, or Undo on the page |
| `REASON_REPLY` | a STOP reply or button | withdrawn | only by `resume()` (a START reply) |
| `REASON_UNDELIVERABLE` | the service reported the address undeliverable | kept | by the cron |

- **Automatically:** a nightly cron deletes link and undeliverable stops whose `last_attempt_at` is older than *N*
  days (default 90). An attacker who keeps trying keeps the stop alive; once they stop, it lifts by itself.
- **Undo** on the page right after stopping – only for a link stop, so a stop link never lifts a STOP reply.

## Interfaces and settings

- `Api\StopListInterface`: `isStopped()`, `getReason()`, `stop()`, `resume()`, `touch()` (restart the timer),
  `getStopUrl()`. Replaceable with a di.xml preference. Each throws `\InvalidArgumentException` for an address the
  channel cannot take (it normalizes to `''`, e.g. a phone number without `+`), so a malformed address is never
  stored or looked up under a key of its own; normalize or prefix it first (a webhook, see module-authors).
- **Stores > Configuration > CommerceKings > Notifications > Do not contact**: *"Stop these emails" link* (store
  view, default Yes; always on in opt-out mode, where it is the only way to stop the emails), *Lifts after (days
  without attempts)* (global, default 90).
- **One-click unsubscribe (RFC 8058):** the stop link also accepts the mail provider's POST (body exactly
  `List-Unsubscribe=One-Click`, no form key): it stops and answers 200, a malformed or forged one 400. It never
  undoes. A feature uses it as the one-click URL when it has no unsubscribe link of its own (abandoned cart in
  opt-out mode).

## Where it lives

| Module | Changes |
|---|---|
| Notification | table, interface and model, silent subscribe in `SubscriptionManagement`, `stoppable` in `Delivery`, `Sender` and `SubscriptionTypeInterface`, a storefront route (stop page with Undo), cleanup cron, settings, docs |
| Abandoned cart | `stop_url` in the reminder template, passed by `ReminderData` |
| Consent, consent bridge, others | none – the stop sits on top of consent and wins |

## Edge cases

- A scanner opening the link: nothing happens, only the POST acts.
- A forged or altered link: "Link not valid", nothing changes. Links do not expire; the stop they create
  lifts by itself after *Lifts after (days without attempts)*.
- A logged-in customer's address can be stopped too; the account does not prove the email.

## Tests

- Unit: hash and signature, silent subscribe restarting the timer, sender drops stoppable deliveries and passes service
  messages, cron deletes only quiet stops.
- End-to-end on a sandbox: 3 attacker carts → stop → no more email → a new attempt restarts the timer → 90 quiet
  days (simulated) → cron lifts the stop → the next cart is reminded; plus Undo.

## Encryption key change

The hashes and links are keyed with Magento's encryption key. Only hashes are stored, so a stop can never be
re-hashed from its address – but Magento keeps the old keys in `env.php` after `encryption:key:change`, so:

- **Stops keep working.** A recipient is looked up under every listed key; a stop found under an older key is
  moved to the newest one, so it also survives the later removal of the
  old key.
- **Stop links in emails already sent keep working**, by page and by RFC 8058 one-click – but only to **stop**.
  Undo needs a link signed with the newest key, so a leaked old key can never turn emails back on.
- **The cooldown restarts**: it only uses the newest key.
- Older keys are used only after a self-check: computing the newest key's hash must give Magento's own (with a
  replaced encryptor that hashes differently, only the newest key counts).

Subscriptions, contacts and consent records are not affected, and consent withdrawal links in emails already sent
keep working while the old key stays in `env.php`.

Nothing needs to be run after a key change; restart the queue consumers and PHP-FPM (or reset opcache), so no
running process keeps using the old key. Leftover rows go by themselves: cooldown rows of an older key within the
cooldown period, stops within *Lifts after (days without attempts)* once nothing matches them.

**Don't remove an old key from `env.php`** while stops may still be stored under it (e.g. soon after the change, or
because it leaked): stops not yet moved to the newest key would no longer match and lift, and stop links signed
with it would stop working. Removed stops cannot be restored.

## Provider feedback

- A service reporting an undeliverable address (e.g. an SMS status callback) stops it with
  `REASON_UNDELIVERABLE`: suppressed, consents kept, lifts after the configured days. A STOP reply is `REASON_REPLY`.

## Later

- Email bounce and spam-complaint feedback from mail providers (the same `REASON_UNDELIVERABLE` / `REASON_REPLY`
  calls; no email provider module sends them yet).
