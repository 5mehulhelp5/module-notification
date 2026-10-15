# CommerceKings_Notification

Shared email delivery for CommerceKings notifications (e.g. `commercekings/module-abandoned-cart`):

- **One queue consumer** `commerceKingsNotificationSend` for every notification type. Messages are
  `<type>|<reference>`; the type re-checks its own rules on fresh data and sends.
- **Mailer**: optional recipient cooldown, recipient re-check, content built in the message's store,
  optional RFC 8058 one-click unsubscribe headers. A failure before the mail server accepted the email
  releases the cooldown claim; a failure after it surfaces as `MessageSentException` so it is never sent twice.
- **Recipient cooldown**: minimum gap between emails to the same address across all notifications that
  use it and all stores. Keyed hashes only (`ck_notification_cooldown`), never an address.

The module knows no consent and no feature: features check their own consent and pass their own
unsubscribe URL.

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

## Admin

**Stores > Configuration > CommerceKings > Notifications**: *Email cooldown (days)*, default 7,
**0 turns it off**.

## Operation

- Consumers run from cron (`cron_consumers_runner`); with RabbitMQ unavailable, Magento's
  `magento/module-mysql-mq` database transport works too.
- After deploying new code, restart the resident consumer (it keeps old code in memory).
- Cron `commercekings_notification_cooldown_cleanup` (daily 03:00) removes expired cooldown rows.

## Tests

From the Magento root, with the package installed:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification/Test/Unit
```

See [compatibility](COMPATIBILITY.md) and [changes](CHANGELOG.md).
No public release has been tagged or published yet.
