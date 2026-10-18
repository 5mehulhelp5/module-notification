# Changelog

## Unreleased

- **Channel-generic sign-ups:** contacts store `channel` and `recipient` (an email address, a phone number, …);
  `SubscriptionTypeInterface::getChannel()` says which channel a type's subscribers are reached on, and guests
  subscribe with one `recipient` field (REST `{"type", "reference", "recipient"}`), validated by the channel's
  normalizer. A stop removes the recipient's
  stoppable subscriptions on its channel. Proven with a test SMS and WhatsApp channel (`MultiChannelTest`).
- **Permission per type:** `SubscriptionTypeInterface::getPermission()` – a type may bring its own permission (e.g. an
  SMS checkbox); `null` keeps the shop-wide one. Sign-up, the send-time guard and `Model\TypePermission` (a feature's
  claim) all follow it.
- A provider without one-click unsubscribe (e.g. SMS) is not given the stop link as a one-click URL, so nothing is
  logged; the stop page says "messages" and "number" for channels other than email.
- `ProviderPool` and `RecipientNormalizerPool` are `@api`: their di.xml arguments are stable extension points.
- **"Stop" everywhere:** `StopListInterface` (`isStopped`, `stop`, `resume`, `getStopUrl`), `SenderInterface::STOPPED`,
  the `stop_url` variable, `/cknotification/stop` and `ck_notification_stop`. "Do not contact" stays the
  people-facing name.
- `SubscriptionTypeInterface::isStoppable()` and `Delivery::$stoppable`: whether the recipient can stop it with Do not
  contact – the feature decides (a cart reminder yes, an order message no). A stop removes only stoppable
  subscriptions and matches customer accounts exactly like contacts (letter case and spaces ignored, accents not). A
  stoppable send without an unsubscribe link of its own gets the stop link as its one-click URL.
- In opt-out mode the "Stop these emails" link is always offered (it is the only way to stop the emails there),
  and the stop link accepts the mail provider's RFC 8058 one-click POST (stops, never undoes).
- **Encryption key change:** stops and stop links keep working after `encryption:key:change` while the old key stays
  in `env.php`: stops are looked up under every listed key and moved to the newest one when found; older-key links
  can stop (also one-click) but never undo. Nothing to run; leftover rows go with the normal cleanups.
- **Do not contact:** a signed "stop these emails" link in marketing notifications (`StopListInterface`,
  `/cknotification/stop` with a confirmation and Undo) stops all marketing notifications to that address. Stopped
  recipients are not subscribed again (answered as stored, so the list cannot be probed); the sender drops stoppable
  deliveries (`STOPPED`) and passes the others (`Delivery::$stoppable = false`). Each attempt restarts the lift
  timer; a cron lifts stops after *Lifts after (days without attempts)*, default 90. Keyed hashes only.
- **Providers:** sending goes through `SenderInterface` to the provider selected per channel (`ProviderInterface`,
  `ProviderPool`; setting *Email provider*, per website, default *Magento email*). Provider modules add their own
  provider and settings. `Delivery::text()` builds a plain-text delivery (e.g. SMS) without a template or sender.
  A provider says whether it adds one-click unsubscribe headers (`supportsOneClickUnsubscribe()`); the sender gives
  the one-click URL only to those that do and logs a warning otherwise. A per-channel `RecipientNormalizerInterface`
  gives each recipient one canonical form for the cooldown and the provider; the cooldown is kept per channel.
- One-click unsubscribe with an http store URL sends the email without the headers and logs a warning, instead of
  failing the send.
- Opt-out mode (`OpenPermission`) accepts only subscriptions made without a permission reference: after switching
  from opt-in, a subscription whose consent expired or was withdrawn does not come back to life.
- Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`): Magento 2.4.0–2.4.3 do not run on PHP 8.1.
- **Opt-out notice:** `SubscriptionPermissionInterface::isOptOut()` (true for `OpenPermission`) and
  `OptOutNoticeInterface`, which resolves a feature's *Show notice* (Automatic / Yes / No) and *Notice text* settings.
  Automatic shows the notice in opt-out mode and hides it with a permission module such as the consent bridge.
- Commit guest contact acquisition and subscription creation together, retaining the contact row lock until the
  subscription exists. Cover old-contact reuse during concurrent cleanup and rollback on storage failures.

- One address normalization (`ContactRepository::normalize()`: trim, Unicode NFC, lower case) for contacts and the
  recipient cooldown, so one contact is one cooldown.
- Add failure tests for contact/subscription persistence, recipient lookup, invalid website ids and empty queue references, including retry behavior and suppression-claim release.
- **Shared sending** for CommerceKings notifications: one queue consumer `commerceKingsNotificationSend`
  (RabbitMQ or Magento's database queue) dispatching `<type>|<reference>` to registered notification types.
- **Magento email provider:** store-emulated content, recipient re-check, optional RFC 8058 one-click headers (setting *One-click unsubscribe*, per website, default No),
  also on Magento 2.4.4–2.4.7 through a polyfill for Magento's Laminas transport; a mail transport replaced by an
  extension sends without them and logs a warning instead of failing;
  `MessageSentException` when a step fails after the mail server accepted the email.
- **Recipient cooldown:** optional per delivery (`Delivery::$useCooldown`) and per shop
  (*Cooldown (days)*, per channel, default 7, 0 = off); keyed hashes only, pruned daily.
- **Subscriptions** (`ck_notification_subscription`): one subscribe endpoint for every type
  (`/V1/ck-notification/subscriptions`, `/mine` for customers) with shared email rules, the type's
  authorization, the permission for its purpose (opt-out alone, consent with `commercekings/module-consent-notification`)
  and one guarded write; no dependency on `commercekings/module-consent`;
  one withdrawal handler for every type; the consumer only hands over valid subscriptions; a daily cleanup
  removes subscriptions whose item is gone (`orphanCondition()`).
- **Permission contract:** `SubscriptionPermissionInterface` (check, SQL condition, and whether the current visitor
  gave a permission) and `SubscriptionRemovalInterface`; subscribe, the guard and withdrawal use them instead of
  Consent directly. Subscriptions store no browser. The default `OpenPermission` allows every authorized
  subscription (opt-out); `commercekings/module-consent-notification` replaces it with Consent and registers the
  withdrawal handler. The subscription stores the permission's reference in `permission_ref`; `Subscription::$permissionRef`. The permission also rechecks a stored reference at send time (`isValidFor()`);
  `SubscriberInterface` says whether the current visitor is the subscriber and gives the unsubscribe link (default
  `AnonymousSubscriber`: neither); the storefront asks `CommerceKings_Notification/js/permission`.
- **Guest contacts** (`ck_notification_contact`, `ContactRepositoryInterface`) shared by notification
  features, keyed by an exact normalized address (`recipient_key`), with reference-based cleanup of unused contacts.

No public release has been tagged or published yet.
