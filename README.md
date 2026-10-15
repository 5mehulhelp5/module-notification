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
- **Contacts**: guest email addresses, one per website and address (`ck_notification_contact`).
  Customers use their account email. Cron `commercekings_notification_contact_cleanup` (daily 03:15) removes
  contacts nothing uses after a 24-hour grace period; modules that store contact ids themselves register an
  `Api\ContactReferenceInterface` (`Model\ContactReferencePool` "references").
- **One queue consumer** `commerceKingsNotificationSend` for every type. Messages are `<type>|<reference>`;
  for subscription types the reference is the subscription id, and only a subscription that still exists with
  a valid consent is handed over.
- **Mailer**: optional recipient cooldown, recipient re-check, content built in the message's store,
  optional RFC 8058 one-click unsubscribe headers. A failure before the mail server accepted the email
  releases the cooldown claim; a failure after it surfaces as `MessageSentException` so it is never sent twice.
- **Recipient cooldown**: minimum gap between emails to the same address across all notifications that
  use it and all stores. Keyed hashes only (`ck_notification_cooldown`), never an address.

## Subscribe

| Endpoint | Who | Body |
| --- | --- | --- |
| `POST /rest/V1/ck-notification/subscriptions` (anonymous) | guest | `{"type", "reference", "email"}`; `""` unsubscribes |
| `POST /rest/V1/ck-notification/subscriptions/mine` (`self`) | customer: storefront AJAX session or token | `{"type", "reference"}` |

Both return `true` when stored (including an identical retry) and `false` when refused or removed. An unknown
type or invalid email is Magento's 400 without echoing the address.

- **Guest:** an empty email, missing consent or a type that is not accepting removes the guest's subscription
  of a single type. Subscriptions of a one-per-person type are removed by the withdrawal link only.
- **Customer:** without a consent in this browser nothing changes; a consent from another device stays.
- A change of contact, customer, consent or browser sets `captured_at` (a type may use it to restart a delay);
  an identical retry does not.

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

### Subscription types

For notifications a visitor signs up for, also:

1. Implement `Api\SubscriptionTypeInterface`:
   - `getPurpose()`: the Consent purpose (declared by your module);
   - `isSingle()`: one subscription per reference (a new email replaces it) or one per person;
   - `authorize($reference, $customerId)`: `null` when the visitor may not touch it, else a `Data\Target`
     with your id, the store, whether it accepts subscriptions now, and an optional trusted SQL condition
     repeated in the write (e.g. "the cart is still active in this store").
2. Register it under the same code, and Notification's withdrawal handler under your purpose:
   ```xml
   <type name="CommerceKings\Notification\Model\SubscriptionTypePool">
       <arguments>
           <argument name="types" xsi:type="array">
               <item name="my_notification" xsi:type="object">Vendor\Module\Model\MySubscriptionType</item>
           </argument>
       </arguments>
   </type>
   <type name="CommerceKings\Consent\Model\WithdrawalPool">
       <arguments>
           <argument name="handlers" xsi:type="array">
               <item name="my_purpose" xsi:type="array">
                   <item name="notification_subscriptions" xsi:type="object">CommerceKings\Notification\Model\WithdrawalHandler</item>
               </item>
           </argument>
       </arguments>
   </type>
   ```
   Without the handler nothing is sent anyway (the consumer and claims check the consent); only cleanup waits.
3. Queue the **subscription id**; read it with `Api\SubscriptionRepositoryInterface` (`getEmail()` gives the
   contact's address or the customer's current one) and put `Api\SubscriptionGuardInterface::validCondition()`
   into your atomic claim.
4. Keep your send history keyed by what "once" means for you (e.g. the cart), never by the subscription id:
   a withdrawal deletes the subscription, and subscribing again creates a new one.

## Admin

**Stores > Configuration > CommerceKings > Notifications**: *Email cooldown (days)*, default 7,
**0 turns it off**.

## Operation

- After deploying new code, **restart the resident consumer** (it keeps the old code in memory): stop the
  running `queue:consumers:start commerceKingsNotificationSend` process; `cron_consumers_runner` starts it
  again on the next cron run. `queue/consumers_wait_for_messages` controls whether it waits for more
  messages when the queue is empty.
- Cron `commercekings_notification_cooldown_cleanup` (daily 03:00) removes expired cooldown rows.

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
