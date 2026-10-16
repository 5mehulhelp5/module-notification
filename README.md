# Notifications

The one way CommerceKings features subscribe shoppers and send them emails. A feature (e.g. abandoned cart) says
*what and when*; Notification handles *who and how*: subscriptions, guest contacts, one queue consumer, the mailer
and a recipient cooldown.

## With or without consent

- **Opt-out (Notification alone)** – every subscription is allowed until the shopper unsubscribes; features show
  their notice where shoppers subscribe.
- **Opt-in** – add the bridge `commercekings/module-consent-notification`: a subscription needs a consent from
  `commercekings/module-consent` for the feature's purpose, withdrawing it removes the subscription, and notices are
  off by default.

```sh
composer require commercekings/module-notification:^0.1
bin/magento setup:upgrade
```

Usually you don't require it yourself – a feature module brings it.

## Settings

**Stores > Configuration > CommerceKings > Notifications**:

| Field | Default |
|---|---|
| Email cooldown (days, global) | 7 – minimum gap between emails to one address; 0 turns it off |
| One-click unsubscribe (website) | No – RFC 8058 headers; the feature's unsubscribe link must accept the POST |

## Before going live

- Run the consumer `commerceKingsNotificationSend` (RabbitMQ or Magento's database queue) with
  `--max-messages=1000`, and restart it after each deployment.
- Rate-limit `POST /rest/<store>/V1/ck-notification/subscriptions` per IP in nginx or your proxy, e.g. 10 a minute.

Both: [operations](docs/operations.md).

## Extending

`Api\NotificationTypeInterface` – a notification type, registered in a pool

`Api\QueueInterface` – publish its messages

`Api\SubscriptionTypeInterface` – something a shopper subscribes to

`Api\SubscriptionPermissionInterface` – who may subscribe (the consent bridge replaces it)

`Api\SubscriberInterface` – who the subscriber is and their unsubscribe link

`Api\OptOutNoticeInterface` – a notice where shoppers subscribe (Automatic, Yes or No)

Details: [for module authors](docs/module-authors.md).

## Limitations

- No rate limit in code: protect the guest endpoint in front of Magento.
- A failed send is not retried by Notification; the feature decides (abandoned cart queues the cart again).

## More

[How it works](docs/how-it-works.md) · [For module authors](docs/module-authors.md) · [Operations](docs/operations.md) ·
[Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)
