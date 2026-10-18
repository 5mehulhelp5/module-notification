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
- The link carries the recipient encrypted with Magento's key (`r`) and an HMAC signature over it (`s`); no
  address appears in the URL.
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
- **Stores > Configuration > CommerceKings > Notifications > Do not contact**: *"Stop these emails" link* (store
  view, default Yes; always on in opt-out mode, where it is the only way to stop the emails), *Lifts after (days
  without attempts)* (global, default 90).
- **One-click unsubscribe (RFC 8058):** the stop link also accepts the mail provider's POST (body exactly
  `List-Unsubscribe=One-Click`, no form key): it blocks and answers 200, a malformed or forged one 400. It never
  undoes. A feature uses it as the one-click URL when it has no unsubscribe link of its own (abandoned cart in
  opt-out mode).

## Where it lives

| Module | Changes |
|---|---|
| Notification | table, interface and model, silent subscribe in `SubscriptionManagement`, `marketing` in `Delivery` and `Sender`, a storefront route (block page with Undo), cleanup cron, settings, docs |
| Abandoned cart | `block_url` in the reminder template, passed by `ReminderData` |
| Consent, consent bridge, others | none – the block sits on top of consent and wins |

## Edge cases

- A scanner opening the link: nothing happens, only the POST acts.
- A forged or altered link: "Link not valid", nothing changes. Links do not expire; the block they create
  lifts by itself after *Lifts after (days without attempts)*.
- A logged-in customer's address can be blocked too; the account does not prove the email.

## Tests

- Unit: hash and signature, silent subscribe restarting the timer, sender drops marketing and passes service
  messages, cron deletes only quiet blocks.
- End-to-end on a sandbox: 3 attacker carts → block → no more email → a new attempt restarts the timer → 90 quiet
  days (simulated) → cron lifts the block → the next cart is reminded; plus Undo.

## Encryption key change

The hashes and links are keyed with Magento's encryption key. Only hashes are stored, so a block can never be
re-hashed from its address – but Magento keeps the old keys in `env.php` after `encryption:key:change`, so:

- **Blocks keep working.** A recipient is looked up under every listed key; a block found under an older key is
  moved to the newest one (and its fingerprint, `key_id`, updated), so it also survives the later removal of the
  old key.
- **Stop links in emails already sent keep working**, by page and by RFC 8058 one-click – but only to **block**.
  Undo needs a link signed with the newest key, so a leaked old key can never turn emails back on.
- **The cooldown restarts**: it only uses the newest key.
- Older keys are used only after a self-check: computing the newest key's hash must give Magento's own (with a
  replaced encryptor that hashes differently, only the newest key counts).

Subscriptions, contacts and consent records are not affected, and consent withdrawal links in emails already sent
keep working while the old key stays in `env.php`.

After `bin/magento encryption:key:change`, restart the queue consumers and PHP-FPM (or reset opcache), so no
running process keeps using the old key. Then, on a server with the shop's `env.php`:

```sh
bin/magento notification:key-change:cleanup --dry-run   # rows per key; removes nothing
bin/magento notification:key-change:cleanup             # removes cooldown rows of earlier keys
```

The dry run shows, per table, the rows of the **current** key, of an **earlier** key still listed in `env.php`, of
an **unknown** key (no longer listed) and **unmarked** rows. By default the cleanup removes only earlier-key cooldown
rows – blocks of a listed key are never removed. If you removed an old key from `env.php` (e.g. because it leaked),
its blocks not yet moved can no longer match: `--force` removes those unknown-key rows, logs the counts and posts
one admin notification with their number, so the shop owner knows that recipients who had stopped emails may get
them again. A server with the wrong `env.php` sees every row as unknown, so never run `--force` there (the dry run
shows it: many unknown and no current rows). Unmarked rows are never removed; they age out through the normal
cleanups. Removed blocks cannot be restored. The command never runs automatically.

- Blocking and cooldown claims store the current fingerprint; `setup:upgrade` only adds the column and never
  changes rows.

## Later

- Bounce and spam-complaint feedback from providers, blocking a recipient automatically.
