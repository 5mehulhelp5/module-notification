# <a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-notification/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-notification/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>Notifications

Works with Magento 2.4+ · Luma and Hyvä · PHP 8.1–8.5 · RabbitMQ or MySQL queue

[![License](https://badgen.net/github/license/commercekings/module-notification)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-notification)](https://github.com/commercekings/module-notification/releases) [![Checks](https://badgen.net/github/checks/commercekings/module-notification/main?label=checks)](https://github.com/commercekings/module-notification/actions)

**Every email sent in the background – never during checkout, never in a cron loop – so your store stays fast.**

- **Fast checkout.** Shoppers only subscribe – one quick write. A queue consumer sends later.
- **Scales with you.** Add consumers when traffic grows; RabbitMQ or Magento's MySQL queue.
- **Private by default.** The cooldown keeps hashes, never addresses; unused contacts are removed.
- **One engine for every notification** – opt-in or opt-out, guests and customers, optional RFC 8058 one-click unsubscribe.

A feature (e.g. abandoned cart) says *what and when*; Notification handles *who and how*.

## Installation

Usually a feature brings it. On its own:

```sh
composer require commercekings/module-notification:^0.1
bin/magento setup:upgrade
```

Alone it runs **opt-out**: a subscription stays until it is removed, and features show their notice. Add
`commercekings/privacy-bundle` for **opt-in**: a subscription then needs a consent for the feature's purpose,
withdrawing it removes the subscription, and notices are off by default.

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

## Disclaimer

These modules help you ask for, record and respect your shoppers' choices – they don't make your store compliant on
their own. You are responsible for your store's privacy: what you collect, the wording you show, your privacy policy
and whether your setup meets the laws that apply to you (e.g. GDPR, ePrivacy, email marketing rules). Get legal advice
if you are unsure.
