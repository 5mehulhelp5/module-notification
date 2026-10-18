# How it works

Subscriptions, contacts, sending and cooldown in `commercekings/module-notification`. Start with the [README](../README.md).

## Parts

The single way to subscribe to and send CommerceKings notifications (e.g. `commercekings/module-abandoned-cart`).
Each module answers one question:

```
 PERMISSION     "May we?"          optional: consent per purpose (module-consent via module-consent-notification)
 NOTIFICATION   "Who, and how?"    email rules, contacts, subscriptions, withdrawal, delivery
 FEATURE        "What, and when?"  authorize a reference, its trigger, its content, its send history
```

- **Subscriptions** (`ck_notification_subscription`): who wants which notification type for which reference,
  under which consent. No row = not subscribed; a withdrawal deletes it.
- **Subscribe endpoint** for every type. Order, nothing written before the last step: email rules → the type's
  `authorize()` → consent for the type's purpose and website → contact → one INSERT that repeats the consent
  check and the type's condition, so a withdrawal or a changed reference at the same moment wins.
- **Withdrawal**: one handler removes the subscriptions of withdrawn consents for every type.
- **Contacts**: guest email addresses, one per website and address (`ck_notification_contact`). Identity is
  `email_key`, a hash of the trimmed, Unicode-normalized, lower-cased address: letter case is ignored, an
  accent or any other character makes another address (the `email` column's collation would merge them).
  Customers use their account email. Cron `commercekings_notification_contact_cleanup` (daily 03:15) removes
  contacts nothing uses after a 24-hour grace period; modules that store contact ids themselves register an
  `Api\ContactReferenceInterface` (`Model\ContactReferencePool` "references").
  Guest capture acquires the contact and creates its subscription in one short transaction, so cleanup
  cannot remove an old contact while it is being reused. Failed storage rolls back both writes.
- **One queue consumer** `commerceKingsNotificationSend` for every type. Messages are `<type>|<reference>`;
  for subscription types the reference is the subscription id, and only a subscription that still exists with
  a valid consent is handed over.
- **Sender** (`Api\SenderInterface`): optional recipient cooldown, recipient re-check, content built in the delivery's
  store, then the provider selected for the channel (**Email provider**, per website, default *Magento email*). A
  failure before the provider accepted it releases the cooldown claim; a failure after it surfaces as
  `MessageSentException` so it is never sent twice.
- **Providers** (`Api\ProviderInterface`, registered in `Model\ProviderPool`): deliver a notification on one channel.
  The built-in *Magento email* provider uses Magento's email templates and mail transport, with
  optional RFC 8058 one-click unsubscribe headers (**One-click unsubscribe**, Yes/No per website, default No; the
  type's unsubscribe link must accept the mail client's POST). Magento 2.4.8+ adds them through Symfony Mailer;
  2.4.4–2.4.7 through a polyfill for Magento's Laminas transport (`Model\OneClickHeaders`). A mail transport
  replaced by an extension (e.g. an SMTP module) sends without them and logs a warning.
- **Recipient cooldown**: minimum gap between emails to the same address across all notifications that
  use it and all stores. Keyed hashes only (`ck_notification_cooldown`), never an address.
- **Do not contact** (`Api\RecipientBlockInterface`): a signed "stop these emails" link in marketing notifications
  opens `/cknotification/block`; only its form-key POST blocks, so mail scanners change nothing. Blocking removes
  the recipient's subscriptions; sign-ups for a blocked recipient are answered as stored but not written and restart
  the lift timer; the sender drops marketing deliveries (`BLOCKED`) and passes service ones (`Delivery::$marketing
  = false`). A cron lifts blocks after *N* days without attempts. Keyed hashes only
  (`ck_notification_recipient_block`); the link carries the recipient encrypted. Design: [recipient block](recipient-block.md).
- **Encryption key change**: blocks and cooldown rows store a fingerprint of the key that made their hash (`key_id`).
  After `encryption:key:change` they can no longer match; `bin/magento notification:key-change:cleanup` (run by
  hand, `--dry-run` only counts) removes rows with another key's fingerprint (never rows without one), logs the
  counts and, when blocks were lifted, posts one admin notification
  (`Magento\Framework\Notification\NotifierInterface`; shown by Magento_AdminNotification when installed).

## Subscribe

Abuse protection for the anonymous guest endpoint belongs to your infrastructure – see *Rate limiting* below.
Without it, the recipient cooldown (one email per address per configured days) and the feature's own sending
limits cap how often a stranger's address can be mailed, but not how fast a script can subscribe addresses.

| Endpoint | Who | Body |
| --- | --- | --- |
| `POST /rest/V1/ck-notification/subscriptions` (anonymous) | guest | `{"type", "reference", "email"}`; `""` unsubscribes |
| `POST /rest/V1/ck-notification/subscriptions/mine` (`self`) | customer: storefront AJAX session or token | `{"type", "reference"}` |

Both return `true` when stored (including an identical retry) and `false` when refused or removed. An unknown
type or invalid email is Magento's 400 without echoing the address.

- **Guest:** an empty email, missing consent or a type that is not accepting removes the guest's subscription
  of a single type. Subscriptions of a one-per-person type are removed by the withdrawal link only.
- **Customer:** without a consent in this browser nothing changes; a consent from another device stays.
- A change of contact, customer or consent sets `captured_at` (a type may use it to restart a delay);
  an identical retry does not.

## Admin

**Stores > Configuration > CommerceKings > Notifications**: *Email provider*, default *Magento email* (per website);
*Cooldown (days)*, per channel, default 7, **0 turns it off** (global); *One-click unsubscribe*, default No (per website) – RFC 8058 headers on every
notification whose content passes an HTTPS unsubscribe URL (an http URL sends the email without them and logs a
warning); that URL must accept the mail client's POST.

## Tests

From the Magento root, with the package installed:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification/Test/Unit
```
