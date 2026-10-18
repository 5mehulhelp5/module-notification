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
  under which permission (a consent, with the bridge). No row = not subscribed; a withdrawal deletes it.
- **Subscribe endpoint** for every type. Order, nothing written before the last step: email rules → the type's
  `authorize()` → the permission for the type's purpose and website → contact → one INSERT that repeats the
  permission check and the type's condition, so a withdrawal or a changed reference at the same moment wins.
- **Withdrawal**: one handler removes the subscriptions of withdrawn permissions for every type.
- **Contacts**: guest recipients – an email address, a phone number, … – one per website, channel and address
  (`ck_notification_contact`: `channel`, `recipient`). Identity is `recipient_key`, a hash of the normalized
  address: for email trimmed, Unicode-normalized and lower-cased (letter case is ignored, an accent or any other
  character makes another address – the `recipient` column's collation would merge them); for other channels the
  channel's `RecipientNormalizerInterface`. Customers use their account email. Cron `commercekings_notification_contact_cleanup` (daily 03:15) removes
  contacts nothing uses after a 24-hour grace period; modules that store contact ids themselves register an
  `Api\ContactReferenceInterface` (`Model\ContactReferencePool` "references").
  Guest capture acquires the contact and creates its subscription in one short transaction, so cleanup
  cannot remove an old contact while it is being reused. Failed storage rolls back both writes.
- **One queue consumer** `commerceKingsNotificationSend` for every type. Messages are `<type>|<reference>`;
  for subscription types the reference is the subscription id, and only a subscription that still exists with
  a valid permission is handed over.
- **Sender** (`Api\SenderInterface`): stop list and quiet hours for stoppable deliveries, optional recipient cooldown,
  recipient re-check, content built in the delivery's store, then the provider selected for the channel (**Email
  provider**, per website, default *Magento email*). A failure before the provider accepted it releases the cooldown
  claim; a failure after it surfaces as `MessageSentException` so it is never sent twice.
- **Providers** (`Api\ProviderInterface`, registered in `Model\ProviderPool`): deliver a notification on one channel.
  The built-in *Magento email* provider uses Magento's email templates and mail transport, with
  optional RFC 8058 one-click unsubscribe headers (**One-click unsubscribe**, Yes/No per website, default No; the
  type's unsubscribe link must accept the mail client's POST). Magento 2.4.8+ adds them through Symfony Mailer;
  2.4.4–2.4.7 through a polyfill for Magento's Laminas transport (`Model\OneClickHeaders`). A mail transport
  replaced by an extension (e.g. an SMTP module) sends without them and logs a warning.
- **Recipient cooldown**: minimum gap between notifications to the same recipient on a channel, across all
  notifications that use it and all stores. Keyed hashes only (`ck_notification_cooldown`), never an address.
- **Do not contact** (`Api\StopListInterface`): a signed "stop these emails" link in marketing notifications
  opens `/cknotification/stop`; only its form-key POST stops, so mail scanners change nothing. Stopping removes
  the recipient's subscriptions; sign-ups for a stopped recipient are answered as stored but not written and restart
  the lift timer; the sender drops stoppable deliveries (`STOPPED`) and passes the others (`Delivery::$stoppable
  = false`); a stoppable send without its own unsubscribe link gets the stop link as its one-click URL. A cron lifts stops after *N* days without attempts. Keyed hashes only
  (`ck_notification_stop`); the link carries the recipient encrypted. Design: [stop list](stop-list.md).
- **Quiet hours** (`Api\QuietHoursInterface`): a daily window (e.g. 21:00–08:00, may cross midnight) in which
  stoppable deliveries on the selected channels are held (`QUIET_HOURS`, before any cooldown claim) and sent by the
  feature after it (`nextAllowedAt()`, UTC). Service messages are never held. The default uses the store's time zone
  (*General > Locale Options*); on the day clocks go forward a time in the skipped hour means the first time after it,
  on the day they go back a time in the repeated hour means its first occurrence. Rules based on the recipient's local
  time – e.g. US state laws such as Florida's FTSA (08:00–20:00 in the recipient's time zone) – need a
  `QuietHoursInterface` preference that knows it (a planned SMS provider module adds one).
- **Encryption key change**: stops and stop links are checked under every key in `env.php` (`Model\KeyedHash`); a
  stop found under an older key moves to the newest one, and an older-key link can stop but never undo. Nothing to
  run: leftover rows go with the normal cleanups (cooldown period, lift days).

## Subscribe

Abuse protection for the anonymous guest endpoint belongs to your infrastructure first – a web server, proxy or CDN
rule (see [operations](operations.md#rate-limiting)); [commercekings/module-rate-limit](https://github.com/commercekings/module-rate-limit) is an optional second line.
Without it, the recipient cooldown (one email per address per configured days) and the feature's own sending
limits cap how often a stranger's address can be mailed, but not how fast a script can subscribe addresses.

| Endpoint | Who | Body |
| --- | --- | --- |
| `POST /rest/<store>/V1/ck-notification/subscriptions` (anonymous) | guest | `{"type", "reference", "recipient"}` (an email address, or e.g. a phone number for an SMS type); `""` unsubscribes |
| `POST /rest/<store>/V1/ck-notification/subscriptions/mine` (`self`) | customer: storefront AJAX session or token | `{"type", "reference"}`; non-email types add `"recipient"` (e.g. the phone number) |

Both return `true` when stored (including an identical retry) and `false` when refused or removed. An unknown
type or invalid email is Magento's 400 without echoing the address.

- **Guest:** an empty email, a missing permission or a type that is not accepting removes the guest's subscription
  of a single type. Subscriptions of a one-per-person type are removed by the withdrawal link only.
- **Customer:** without a permission in this browser nothing changes; a permission from another device stays.
- A change of contact, customer or permission sets `captured_at` (a type may use it to restart a delay);
  an identical retry does not.

## Admin

**Stores > Configuration > CommerceKings > Notifications**: *Email provider*, default *Magento email* (per website);
*Cooldown (days)*, per channel, default 7, **0 turns it off** (global); *One-click unsubscribe*, default No (per website) – RFC 8058 headers on every
notification whose content passes an HTTPS unsubscribe URL (an http URL sends the email without them and logs a
warning); that URL must accept the mail client's POST.
**Do not contact**: *"Stop these emails" link* (store view, default Yes) and *Lifts after (days without attempts)*
(global, default 90) – see [stop list](stop-list.md).
**Quiet hours** (store view): *Enabled* (default No), *From* / *To* (default 21:00 / 08:00, store time zone) and
*Channels* (none selected, the default: every channel except email).

## Tests

From the Magento root, with the package installed:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification/Test/Unit
```
