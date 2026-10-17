# Notification Engine with Zero Storefront Impact<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-notification/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-notification/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · Luma and Hyvä · PHP 8.1–8.5 · RabbitMQ or MySQL queue

[![License](https://badgen.net/github/license/commercekings/module-notification)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-notification)](https://github.com/commercekings/module-notification/releases) [![Checks](https://github.com/commercekings/module-notification/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-notification/actions/workflows/checks.yml)

**Thousands of shopper notifications a minute or a handful a day – your store never slows down.**

- **Fast storefront.** Shoppers only subscribe – one quick write. A queue consumer sends later.
- **Marketing and service messages.** Reminders shoppers opt into, or order and staff messages that need no subscription.
- **Pluggable channels.** Email built in; SMS, WhatsApp or any other service plugs in as a provider module.
- **Inbox-ready at volume.** Optional RFC 8058 one-click unsubscribe, as Gmail and Yahoo require from bulk senders.
- **Scales with you.** Add consumers when traffic grows; RabbitMQ or Magento's MySQL queue.
- **Private by default.** The cooldown keeps hashes, never addresses; unused contacts are removed.

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

Notification types work **with or without subscriptions**: with them for messages shoppers sign up for, without for
service messages and staff alerts.

`Api\NotificationTypeInterface` – a notification type, registered in a pool

`Api\QueueInterface` – publish its messages

`Api\SenderInterface` – send a `Delivery` (email, or `Delivery::text()` for any other channel)

`Api\ProviderInterface` – deliver on a channel; Magento email is built in, SMS or WhatsApp come as provider modules

`Api\SubscriptionTypeInterface` – something a shopper subscribes to

`Api\SubscriptionPermissionInterface` – who may subscribe (the consent bridge replaces it)

`Api\SubscriberInterface` – who the subscriber is and their unsubscribe link

`Api\OptOutNoticeInterface` – a notice where shoppers subscribe (Automatic, Yes or No)

### Example: an SMS provider

```php
class SmsProvider implements ProviderInterface
{
    public function getCode(): string { return 'my_sms'; }
    public function getLabel(): string { return 'My SMS gateway'; }
    public function getChannel(): string { return 'sms'; }
    public function supportsOneClickUnsubscribe(): bool { return false; }

    public function send(Delivery $delivery, DeliveryContent $content): void
    {
        // post $delivery->recipient and $content->text to your gateway; throw if it is not accepted
    }
}
```

Register it in `Model\ProviderPool` (`etc/di.xml`), then send from a notification type:

```php
$sender->send(Delivery::text($storeId, 'sms', $phone, fn () => new DeliveryContent([], text: 'Your cart is waiting')));
```

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
