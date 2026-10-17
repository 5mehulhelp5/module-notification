# Changelog

## Unreleased

First release. Features:

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
- **Mailer:** store-emulated content, recipient re-check, optional RFC 8058 one-click headers (setting *One-click unsubscribe*, per website, default No),
  also on Magento 2.4.4–2.4.7 through a polyfill for Magento's Laminas transport; a mail transport replaced by an
  extension sends without them and logs a warning instead of failing;
  `MessageSentException` when a step fails after the mail server accepted the email.
- **Recipient cooldown:** optional per message (`Message::$useCooldown`) and per shop
  (*Email cooldown (days)*, default 7, 0 = off); keyed hashes only, pruned daily.
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
  withdrawal handler. The subscription stores the permission's reference in `permission_ref` (renamed from
  `consent_id`, data kept); `Subscription::$permissionRef`. The permission also rechecks a stored reference at send time (`isValidFor()`);
  `SubscriberInterface` says whether the current visitor is the subscriber and gives the unsubscribe link (default
  `AnonymousSubscriber`: neither); the storefront asks `CommerceKings_Notification/js/permission`.
- **Guest contacts** (`ck_notification_contact`, `ContactRepositoryInterface`) shared by notification
  features, keyed by an exact normalized address (`email_key`), with reference-based cleanup of unused contacts.

No public release has been tagged or published yet.
