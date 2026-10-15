# CommerceKings_Notification

**Development status:** first-release feature scope frozen; polishing and verification remain before publication.

Usually installed as a dependency of a notification feature (e.g. `commercekings/module-abandoned-cart`);
directly with `composer require commercekings/module-notification:^0.1` after the first release is published.
Then enable its modules:

```sh
bin/magento module:enable CommerceKings_Core CommerceKings_Consent CommerceKings_Notification
bin/magento setup:upgrade
bin/magento cache:clean
```

The single way to subscribe to and send CommerceKings notifications (e.g. `commercekings/module-abandoned-cart`).
Each module answers one question:

```
 CONSENT        "May we?"          permission per purpose (commercekings/module-consent)
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
- **One queue consumer** `commerceKingsNotificationSend` for every type. Messages are `<type>|<reference>`;
  for subscription types the reference is the subscription id, and only a subscription that still exists with
  a valid consent is handed over.
- **Mailer**: optional recipient cooldown, recipient re-check, content built in the message's store,
  optional RFC 8058 one-click unsubscribe headers (**One-click unsubscribe**, Yes/No per website, default No; the
  type's unsubscribe link must accept the mail client's POST). A failure before the mail server accepted the email
  releases the cooldown claim; a failure after it surfaces as `MessageSentException` so it is never sent twice.
- **Recipient cooldown**: minimum gap between emails to the same address across all notifications that
  use it and all stores. Keyed hashes only (`ck_notification_cooldown`), never an address.

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

## Rate limiting

Rate-limit `POST /rest/<store>/V1/ck-notification/subscriptions` per client IP in front of Magento – it then never
starts for a flood. The storefront scripts treat HTTP 429 as temporary and retry with back-off, so shoppers are not
affected. A checkout sends a few requests per minute at most; suggested: 10 per minute per IP with a small burst.

nginx (keep your usual Magento REST handling in the location):

```nginx
# http {}
limit_req_zone $binary_remote_addr zone=ck_subscribe:10m rate=10r/m;

# server {}
location ~ ^/rest/[^/]+/V1/ck-notification/subscriptions {
    limit_req zone=ck_subscribe burst=5 nodelay;
    limit_req_status 429;
    try_files $uri $uri/ /index.php$is_args$args;
}
```

Behind Cloudflare or another proxy, restore the client IP first (`set_real_ip_from` with the proxy's ranges and
`real_ip_header CF-Connecting-IP`), or use the proxy's own rate-limiting rule on the same path with status 429.
A request rate cannot see what a request contains: a slow script can still try many addresses, so also keep the
recipient cooldown on and set the feature's hourly sending limit (e.g. abandoned cart's *Maximum reminders per hour*).
A bot challenge (e.g. Cloudflare Turnstile) can be added at the same layer; none is shipped.

## For module authors

1. Implement `Api\NotificationTypeInterface::process(string $reference)`: re-check, claim atomically,
   then `Api\MailerInterface::send(new Message(...))`.
2. Register it in your `etc/di.xml`:
   ```xml
   <type name="CommerceKings\Notification\Model\TypePool">
       <arguments>
           <argument name="types" xsi:type="array">
               <item name="my_notification" xsi:type="object">Vendor\Module\Model\MyNotification</item>
           </argument>
       </arguments>
   </type>
   ```
3. Queue work with `Api\QueueInterface::publish('my_notification', $reference)`.

`Message::$useCooldown = false` for emails the recipient asked for (e.g. back in stock): they are always
sent and do not start the cooldown. `MailerInterface::send()` returns `SENT`, `COOLDOWN` or
`RECIPIENT_CHANGED`; on anything but `SENT`, undo your own claim. Replace the cooldown with a
`CooldownInterface` preference.

### Permission

Who may subscribe is `Api\SubscriptionPermissionInterface`: `check($type, $target)` returns a `Permission`
(granted with a reference stored on the subscription, absent – a guest's single subscription is removed – or
refused – nothing changes), and `validCondition($referenceColumn)` is the SQL that the subscribe INSERT and every
send-time check (`SubscriptionGuardInterface`) contain, so a revocation at the same moment wins.
`identifiesVisitor()` / `isCurrentVisitor($reference)` say whether the current visitor is the one who gave a
permission (e.g. Consent compares the browser the consent was given in); a feature hands over something the
subscriber owns only when it can tell. When a permission is revoked or the shopper unsubscribes,
call `Api\SubscriptionRemovalInterface` (`removeByPermission($references)`, `remove($subscriptionId)`).
At send time `isValidFor($reference, $purpose, $storeId)` rechecks a stored reference in PHP, and
`getUnsubscribeUrl($subscription)` gives the permission's own unsubscribe link (null when it has none). In the
storefront, ask `CommerceKings_Notification/js/permission` (`hasPermission(purpose)`, `onChange(purpose, callback)`)
before subscribing; a permission module maps it to its own with requirejs `map`.
The default, `Model\OpenPermission`, allows every authorized subscription until it is removed (opt-out) and cannot
identify visitors. `commercekings/module-consent-notification` replaces it with CommerceKings_Consent: a subscription
needs a valid consent for the type's purpose, and withdrawing that consent removes it.

### Subscription types

For notifications a visitor signs up for, also:

1. Implement `Api\SubscriptionTypeInterface`:
   - `getPurpose()`: what the subscription is for (with the consent bridge: the Consent purpose your module declares);
   - `isSingle()`: one subscription per reference (a new email replaces it) or one per person;
   - `authorize($reference, $customerId)`: `null` when the visitor may not touch it, else a `Data\Target`
     with your id, the store, whether it accepts subscriptions now, and an optional trusted SQL condition
     repeated in the write (e.g. "the cart is still active in this store");
   - `orphanCondition($referenceIdColumn)`: SQL true once your item is gone (e.g. Magento deleted the cart).
     Notification has no foreign key to your table; this is how a deleted item's subscriptions, and then the
     guest's address, are removed.
2. Register it under the same code:
   ```xml
   <type name="CommerceKings\Notification\Model\SubscriptionTypePool">
       <arguments>
           <argument name="types" xsi:type="array">
               <item name="my_notification" xsi:type="object">Vendor\Module\Model\MySubscriptionType</item>
           </argument>
       </arguments>
   </type>
   ```
   With the consent bridge, withdrawing your purpose's consent removes the subscriptions; nothing else to register.
3. Queue the **subscription id**; read it with `Api\SubscriptionRepositoryInterface` (`getEmail()` gives the
   contact's address or the customer's current one). Repeat the check inside your atomic claim: without the
   subscription table in the UPDATE, add `Api\SubscriptionGuardInterface::validCondition('<subscription id>')`;
   with it joined (e.g. `… JOIN ck_notification_subscription AS s`), check the same row (`s.subscription_id = ?`)
   and `Api\SubscriptionPermissionInterface::validCondition('s.permission_ref')` instead - MySQL does
   not allow a subquery on a table the UPDATE itself joins (error 1093).
4. Keep your send history keyed by what "once" means for you (e.g. the cart), never by the subscription id:
   a withdrawal deletes the subscription, and subscribing again creates a new one.

## Admin

**Stores > Configuration > CommerceKings > Notifications**: *Email cooldown (days)*, default 7,
**0 turns it off** (global); *One-click unsubscribe*, default No (per website) – RFC 8058 headers on every
notification whose content passes an HTTPS unsubscribe URL; that URL must accept the mail client's POST.

## Operation

- After deploying new code, **restart the resident consumer** (it keeps the old code in memory): stop the
  running `queue:consumers:start commerceKingsNotificationSend` process; `cron_consumers_runner` starts it
  again on the next cron run. `queue/consumers_wait_for_messages` controls whether it waits for more
  messages when the queue is empty.
- Cron `commercekings_notification_cooldown_cleanup` (daily 03:00) removes expired cooldown rows.
- Cron `commercekings_notification_subscription_cleanup` (daily 03:10) removes subscriptions whose item is gone
  (each type's `orphanCondition()`); subscriptions of a type that is no longer registered are left alone.
  The contact cleanup (03:15) then removes addresses nothing uses.

## Queue transport

The publisher, topology and consumer use Magento's deployment default connection. Delivery works with
RabbitMQ (`amqp`) and Magento's database queue (`db`); no additional CommerceKings adapter module is required.

For a shop without RabbitMQ, ensure `Magento_MysqlMq` is installed and enabled:

```sh
bin/magento module:status Magento_MysqlMq
bin/magento module:enable Magento_MysqlMq
```

Merge these values into the existing `queue` section of `app/etc/env.php`:

```php
'queue' => [
    'default_connection' => 'db',
    'consumers_wait_for_messages' => 0,
],
```

Keep the other deployment settings. `default_connection` affects every queue without an
explicit XML connection; review other installed modules when changing it. Magento also
defaults to `db` when no external broker or explicit default is configured. The optional
`consumers_wait_for_messages` setting lets consumers exit when no messages remain.
See Adobe's [deployment configuration reference](https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/files/config-reference-envphp).

After enabling the module or changing transport, run:

```sh
bin/magento setup:upgrade
bin/magento cache:clean config
bin/magento queue:consumers:start commerceKingsNotificationSend --max-messages=100
```

Restart existing consumer processes after a transport change. Messages already queued are
not moved between transports; drain the old queue before switching. Keep Magento cron
running for consumer startup and the native SQL queue cleanup job.
Ensure any `cron_consumers_runner/consumers` allowlist includes
`commerceKingsNotificationSend`. Database delivery uses Magento's `queue`, `queue_message`
and `queue_message_status` tables and adds work to the shop database.

A notification type that fails throws; Magento logs it and rejects the message. Retrying is the
type's own job (e.g. abandoned cart releases its claim so the next finder run queues it again).

## Tests

From the Magento root, with the package installed:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification/Test/Unit
```

See [compatibility](COMPATIBILITY.md) and [changes](CHANGELOG.md).
No public release has been tagged or published yet.
