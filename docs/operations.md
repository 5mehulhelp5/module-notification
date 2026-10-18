# Operations

Queue transport, consumers, cron and rate limiting for `commercekings/module-notification`. Start with the [README](../README.md).

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

## Consumers and cron

- After deploying new code, **restart the resident consumer** (it keeps the old code in memory): stop the
  running `queue:consumers:start commerceKingsNotificationSend` process; `cron_consumers_runner` starts it
  again on the next cron run. `queue/consumers_wait_for_messages` controls whether it waits for more
  messages when the queue is empty.
- Cron `commercekings_notification_cooldown_cleanup` (daily 03:00) removes expired cooldown rows.
- Cron `commercekings_notification_subscription_cleanup` (daily 03:10) removes subscriptions whose item is gone
  (each type's `orphanCondition()`); subscriptions of a type that is no longer registered are left alone.
- Cron `commercekings_notification_contact_cleanup` (daily 03:15) then removes contacts nothing uses.
- Cron `commercekings_notification_delivery_state_cleanup` (daily 03:12) removes send history of vanished references
  and, after 90 days, of people without a subscription.
- Cron `commercekings_notification_stop_cleanup` (daily 03:20) lifts Do not contact stops after
  *Lifts after (days without attempts)*.
- Cron `commercekings_notification_address_consent_cleanup` (daily 03:25) deletes withdrawn address consents after
  *Keep withdrawn consents (days)*.

## Rate limiting

**Recommended for every live shop.** The guest subscribe endpoint is anonymous: without a rate limit a script can make
the shop send one reminder to any address it likes – or a paid text to any number – up to the feature's hourly cap (e.g.
abandoned cart's *Maximum reminders per hour*) – and those reminders take the place of real shoppers' in that hour.

Rate-limit `POST /rest/<store>/V1/ck-notification/subscriptions` (and `…/address-consents`, the checkbox consent) per
client IP in front of Magento – it then never
starts for a flood. The storefront scripts treat HTTP 429 as temporary and retry with back-off, so shoppers are not
affected. A checkout sends a few requests per minute at most; suggested: 10 per minute per IP with a small burst.

nginx (keep your usual Magento REST handling in the location):

```nginx
# http {}
limit_req_zone $binary_remote_addr zone=ck_subscribe:10m rate=10r/m;

# server {}
location ~ ^/rest/[^/]+/V1/ck-notification/(subscriptions|address-consents) {
    limit_req zone=ck_subscribe burst=5 nodelay;
    limit_req_status 429;
    try_files $uri $uri/ /index.php$is_args$args;
}
# Magento's asynchronous and bulk REST routes are not used by the storefront
location ~ ^/rest/[^/]+/async(/bulk)?/V1/ck-notification/ {
    return 404;
}
```

Behind Cloudflare or another proxy, restore the client IP first (`set_real_ip_from` with the proxy's ranges and
`real_ip_header CF-Connecting-IP`), or use the proxy's own rate-limiting rule on the same path with status 429.
A request rate cannot see what a request contains: a slow script can still try many addresses, so also keep the
recipient cooldown on and set the feature's hourly sending limit (e.g. abandoned cart's *Maximum reminders per hour*).
A bot challenge (e.g. Cloudflare Turnstile) can be added at the same layer; none is shipped.

**Inside Magento (Magento 2.4.7+):** [commercekings/module-rate-limit](https://github.com/commercekings/module-rate-limit) limits these endpoints per guest IP
and per customer from the admin and answers 429 before it runs. Keep the rule above as the first line of defense –
it stops a flood before PHP starts; the module is the second line.
