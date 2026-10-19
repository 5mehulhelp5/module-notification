# Notification Engine for Heavy Lifting<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-notification/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-notification/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · Luma and Hyvä · PHP 8.1–8.5 · RabbitMQ or MySQL queue

[![License](https://badgen.net/github/license/commercekings/module-notification)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-notification)](https://github.com/commercekings/module-notification/releases) [![Checks](https://github.com/commercekings/module-notification/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-notification/actions/workflows/checks.yml)

**Thousands of notifications a minute or a handful a day – your store never slows down.**

- **Fast storefront.** Every notification is sent in the background by a queue consumer, never during a page view.
  Optional shopper subscriptions are one quick write.
- **Marketing and service messages.** Reminders shoppers opt into, or order and staff messages that need no subscription.
- **Email, SMS, WhatsApp, Viber.** Email built in; a provider module adds a messaging service. Phone numbers, consent
  for a number (a checkbox, stored as evidence), STOP replies and quiet hours are part of Notification.
- **Inbox-ready at volume.** Optional RFC 8058 one-click unsubscribe, as Gmail and Yahoo require from bulk senders.
- **Do not contact.** One click in a marketing email (or a STOP reply) stops all marketing to that address on that
  channel (a STOP to the SMS number doesn't stop email), whoever signs them up again; order messages still arrive.
- **Quiet hours.** Marketing is held at night (on by default for texts, 20:00–08:00) and sent after – in the store's
  time zone, or the recipient's.
- **Scales with you.** Add consumers when traffic grows; RabbitMQ or Magento's MySQL queue.
- **Private by default.** Cooldown, stops and consents keep keyed hashes, never addresses; unused contacts are removed.

A feature (e.g. abandoned cart) says *what and when*; Notification handles *who and how*.

Provider modules: [Twilio](https://github.com/commercekings/module-notification-twilio), [Infobip](https://github.com/commercekings/module-notification-infobip) · Features: [Abandoned Cart](https://github.com/commercekings/module-abandoned-cart) (+ [SMS](https://github.com/commercekings/module-abandoned-cart-sms))

## The model

- A **notification type** is one kind of message, registered by a feature. It decides when to send and builds
  the content.
- A **subscription** is a shopper's sign-up for a type, e.g. reminders for one cart. Marketing types need one; service
  messages don't. Its address is a **contact** (one per website, channel and address) or, on email types, a customer's
  account email.
- A **permission** decides who may subscribe: everyone (opt-out), only with consent (opt-in, with the privacy bundle),
  or – per type – an **address consent**: a checkbox for one number. The **subscriber** provides the unsubscribe link.
- The **queue** carries `<type>|<reference>`. One consumer sends every type, so nothing is sent during a page view.
- The **delivery state** keeps each feature's send history per type and reference, e.g. per cart and reminder type
  (sent, queued, retries), so a new sign-up does not send twice.
- The **sender** checks a **delivery** against Do not contact, quiet hours and the cooldown, builds its content in
  the store's language, then hands it to a provider.
- A **channel** is where a delivery goes: email, SMS, WhatsApp, Viber, or a code a provider module brings. A
  **normalizer** gives each channel one recipient form; SMS, WhatsApp and Viber numbers are E.164.
- A **provider** delivers on one channel, chosen in the admin when there are several. Magento email is built in.
  Install one provider module per channel: recipient time zones and opt-in confirmations are per module.

## Installation

Usually a feature brings it. On its own:

```sh
composer require commercekings/module-notification:^0.1
bin/magento setup:upgrade
```

Alone it runs **opt-out**: a subscription stays until it is removed, and features show their notice. Add
`commercekings/privacy-bundle` for **opt-in**: a subscription then needs a consent for the feature's purpose,
withdrawing it removes the subscription, and notices are off by default. Text messages are always opt-in: a type with an
address consent needs the number's checkbox in either setup.

## Settings

**Stores > Configuration > CommerceKings > Notifications**:

| Group | Fields (default) |
|---|---|
| Delivery | Provider per channel (Magento email); Cooldown in days (7, global, 0 = off); One-click unsubscribe (No) |
| Do not contact | "Stop these emails" link (Yes; always on in opt-out mode); Lifts after days without attempts (90) |
| Quiet hours | Enabled (Yes); From – To (20:00 – 08:00, may cross midnight); Channels (all but email) |
| Consent for an address | Checkbox text for text messages, WhatsApp and Viber, per store view; Keep withdrawn consents (1825 days) |

## Before going live

- Run the consumer `commerceKingsNotificationSend` with `--max-messages=1000` and restart it after each deployment.
- **Recommended:** rate-limit the anonymous routes `POST /rest/<store>/V1/ck-notification/subscriptions` and
  `…/address-consents` per IP (e.g. 10 a minute) in your web server, proxy or CDN, and optionally with
  [commercekings/module-rate-limit](https://github.com/commercekings/module-rate-limit). Without one, a script can make
  the shop message any address – a paid text to any number – up to the feature's hourly cap.

Details: [operations](docs/operations.md).

## Extending

Types work **with or without subscriptions**: with them for messages shoppers sign up for, without for service
messages and staff alerts. The public API (`@api`):

| For | Use |
|---|---|
| A notification type | `Api\NotificationTypeInterface` (`Model\TypePool` "types"), `Api\QueueInterface`, `Api\SenderInterface` with `Data\Delivery` |
| Its send history | `Api\DeliveryStateInterface` |
| Sign-ups | `Api\SubscriptionTypeInterface` (`Model\SubscriptionTypePool` "types"), `Api\SubscriptionManagementInterface` and its `commercekings_notification_subscription_saved` event, `Api\SubscriptionRepositoryInterface`, `Api\SubscriptionGuardInterface`, `Api\SubscriptionRemovalInterface` |
| Who may subscribe | `Api\SubscriptionPermissionInterface`, `Api\SubscriberInterface`; per type `Model\TypePermission`, `Model\TypeSubscriber`, `Model\AddressConsent` (virtual types) |
| A checkbox consent | `Api\AddressConsentManagementInterface`, `address-consent.phtml` with `ViewModel\AddressConsent` |
| A channel or service | `Api\ProviderInterface` (`Model\ProviderPool` "providers"), `Api\RecipientNormalizerInterface` (`Model\RecipientNormalizerPool` "normalizers"), `Model\Config\Source\Provider` (virtual type "channel"), `Model\KeyedHash` |
| Replies and timing | `Api\StopListInterface`, `Api\QuietHoursInterface`, `Api\QuietHoursZoneInterface` (`Model\QuietHours` "zones"), `Api\CooldownInterface` |
| Housekeeping and UI | `Api\ContactReferenceInterface` (`Model\ContactReferencePool` "references"), `Api\OptOutNoticeInterface` |

How to use them, including messaging for provider modules: [for module authors](docs/module-authors.md).

## Limitations

- A failed send is not retried by Notification; the feature decides (abandoned cart queues the cart again).
- Quiet hours have one window per store view for all selected channels.
- Stops, stop links, consents and the cooldown are keyed with Magento's encryption key. After
  `encryption:key:change` nothing needs to be run while the old key stays in `env.php`; the cooldown restarts
  ([details](docs/stop-list.md#encryption-key-change)).

## More

[How it works](docs/how-it-works.md) · [For module authors](docs/module-authors.md) · [Operations](docs/operations.md) ·
[Stop list](docs/stop-list.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

## Disclaimer

These modules help you ask for, record and respect your shoppers' choices – they don't make your store compliant on
their own. You are responsible for your store's privacy: what you collect, the wording you show, your privacy policy and
whether your setup meets the laws that apply to you (e.g. GDPR, ePrivacy, email and SMS marketing rules). Get legal
advice if you are unsure.

---

**Need help installing or setting it up?** **[Get a quote →](https://commercekings.net)**
