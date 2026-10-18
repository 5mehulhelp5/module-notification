# Notification Engine for Heavy Lifting<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-notification/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-notification/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · Luma and Hyvä · PHP 8.1–8.5 · RabbitMQ or MySQL queue

[![License](https://badgen.net/github/license/commercekings/module-notification)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-notification)](https://github.com/commercekings/module-notification/releases) [![Checks](https://github.com/commercekings/module-notification/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-notification/actions/workflows/checks.yml)

**Thousands of notifications a minute or a handful a day – your store never slows down.**

- **Fast storefront.** Every notification is sent in the background by a queue consumer, never during a page view.
  Optional shopper subscriptions are one quick write.
- **Marketing and service messages.** Reminders shoppers opt into, or order and staff messages that need no subscription.
- **Pluggable channels.** Email built in; SMS, WhatsApp or any other service plugs in as a provider module.
- **Inbox-ready at volume.** Optional RFC 8058 one-click unsubscribe, as Gmail and Yahoo require from bulk senders.
- **Do not contact.** A suppression list: one click in a marketing email stops all marketing to that address, whoever
  signs it up again; order emails still arrive.
- **Scales with you.** Add consumers when traffic grows; RabbitMQ or Magento's MySQL queue.
- **Private by default.** The cooldown keeps hashes, never addresses; unused contacts are removed.

A feature (e.g. abandoned cart) says *what and when*; Notification handles *who and how*.

## The model

- A **notification type** is one kind of message, registered by a feature. It decides when to send and builds
  the content.
- A **subscription** is a shopper's sign-up for a type, e.g. reminders for one cart. Marketing types need one;
  service messages don't. A guest is stored as a **contact** (one per website and address); a customer by account.
- A **permission** decides who may subscribe: everyone (opt-out), or only with consent (opt-in, with the privacy
  bundle). Withdrawing the consent removes the subscription. The **subscriber** provides the unsubscribe link.
- The **queue** carries `<type>|<reference>`. One consumer sends every type, so nothing is sent during a page view.
- The **sender** checks a **delivery** against the cooldown and Do not contact, builds its content in the
  store's language, then hands it to a provider.
- A **channel** is where a delivery goes: email, or a code a provider module brings (e.g. `sms`). A **normalizer**
  gives each channel one recipient form, such as phone numbers in E.164.
- A **provider** delivers on one channel; the email provider is chosen in the admin. Magento email is built in, with
  optional one-click unsubscribe; SMS, WhatsApp or an email service come as provider modules.
- The **cooldown** keeps a minimum gap between notifications to one recipient. **Do not contact** stops all
  marketing to a recipient. Both store keyed hashes, never addresses.

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
| Email provider (website) | Magento email – provider modules add their own |
| Cooldown (days, global) | 7 – minimum gap between notifications to one recipient on a channel; 0 turns it off |
| One-click unsubscribe (website) | No – RFC 8058 headers; the feature's unsubscribe link, or the "Stop these emails" link, accepts the POST |
| Do not contact: "Stop these emails" link (store view) | Yes – one click stops all marketing emails to that address; always on in opt-out mode |
| Do not contact: lifts after (days without attempts, global) | 90 – each sign-up attempt for a stopped address restarts it |

## Before going live

- Run the consumer `commerceKingsNotificationSend` (RabbitMQ or Magento's database queue) with
  `--max-messages=1000`, and restart it after each deployment.
- **Recommended:** rate-limit `POST /rest/<store>/V1/ck-notification/subscriptions` per IP, e.g. 10 a minute – in your
  web server, proxy or CDN first, and with [commercekings/module-rate-limit](https://github.com/commercekings/module-rate-limit)
  (Magento 2.4.7+, set in the admin). Without one, a script can make the shop email any address, up to the hourly cap.

Both: [operations](docs/operations.md).

## Extending

Notification types work **with or without subscriptions**: with them for messages shoppers sign up for, without for
service messages and staff alerts.

`Api\NotificationTypeInterface` – a notification type, registered in a pool

`Api\QueueInterface` – publish its messages

`Api\SenderInterface` – send a `Delivery` (email, or `Delivery::text()` for any other channel)

`Api\ProviderInterface` – deliver on a channel; Magento email is built in, SMS or WhatsApp come as provider modules

`Api\RecipientNormalizerInterface` – one canonical recipient form per channel (e.g. phone numbers in E.164)

`Api\RecipientBlockInterface` – Do not contact: the "stop these emails" link and its list

`Api\CooldownInterface` – the minimum gap between notifications to one recipient

`Api\SubscriptionTypeInterface` – something a shopper subscribes to

`Api\SubscriptionPermissionInterface` – who may subscribe (the consent bridge replaces it)

`Api\SubscriberInterface` – who the subscriber is and their unsubscribe link

`Api\SubscriptionGuardInterface` – SQL condition for a feature's atomic send claim

`Api\SubscriptionRepositoryInterface` – read subscriptions

`Api\SubscriptionRemovalInterface` – remove subscriptions (revoked permission, unsubscribe)

`Api\ContactReferenceInterface` – tell the cleanup which contacts your module still uses

`Api\OptOutNoticeInterface` – a notice where shoppers subscribe (Automatic, Yes or No)

Details, including how to build an SMS provider: [for module authors](docs/module-authors.md).

## Limitations

- No rate limit in code: protect the guest endpoint in your web server, proxy or CDN; optionally also with
  [commercekings/module-rate-limit](https://github.com/commercekings/module-rate-limit).
- A failed send is not retried by Notification; the feature decides (abandoned cart queues the cart again).
- Do not contact blocks, the "stop these emails" links and the cooldown are keyed with Magento's encryption key.
  After `encryption:key:change` blocks and stop links keep working while the old key stays in `env.php` (Magento
  keeps it): a block found under an older key moves to the newest one, and an old stop link can still stop the
  emails (one-click too) but not turn them back on. The cooldown restarts. Removing an old key from `env.php` lifts
  the blocks not yet moved and ends links signed with it; `bin/magento notification:key-change:cleanup --force`
  then removes those rows and tells the shop owner in the admin
  ([details](docs/recipient-block.md#encryption-key-change)). Subscriptions, contacts and consent records are not
  affected, and consent withdrawal links keep working the same way; cart restore links expire (see those modules).

## More

[How it works](docs/how-it-works.md) · [For module authors](docs/module-authors.md) · [Operations](docs/operations.md) ·
[Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

## Disclaimer

These modules help you ask for, record and respect your shoppers' choices – they don't make your store compliant on
their own. You are responsible for your store's privacy: what you collect, the wording you show, your privacy policy
and whether your setup meets the laws that apply to you (e.g. GDPR, ePrivacy, email marketing rules). Get legal advice
if you are unsure.
