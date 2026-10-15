# Changelog

## Unreleased

First release. Features:

- Add failure tests for contact/subscription persistence, recipient lookup, invalid website ids and empty queue references, including retry behavior and suppression-claim release.
- **Shared sending** for CommerceKings notifications: one queue consumer `commerceKingsNotificationSend`
  (RabbitMQ or Magento's database queue) dispatching `<type>|<reference>` to registered notification types.
- **Mailer:** store-emulated content, recipient re-check, optional RFC 8058 one-click headers (setting *One-click unsubscribe*, per website, default No);
  `MessageSentException` when a step fails after the mail server accepted the email.
- **Recipient cooldown:** optional per message (`Message::$useCooldown`) and per shop
  (*Email cooldown (days)*, default 7, 0 = off); keyed hashes only, pruned daily.
- **Subscriptions** (`ck_notification_subscription`): one subscribe endpoint for every type
  (`/V1/ck-notification/subscriptions`, `/mine` for customers) with shared email rules, the type's
  authorization, consent for its purpose (requires `commercekings/module-consent`) and one guarded write;
  one withdrawal handler for every type; the consumer only hands over valid subscriptions; a daily cleanup
  removes subscriptions whose item is gone (`orphanCondition()`).
- **Permission contract:** `SubscriptionPermissionInterface` (check, SQL condition, and whether the current visitor
  gave a permission) and `SubscriptionRemovalInterface`; subscribe, the guard and withdrawal use them instead of
  Consent directly. Subscriptions store no browser.
- **Guest contacts** (`ck_notification_contact`, `ContactRepositoryInterface`) shared by notification
  features, keyed by an exact normalized address (`email_key`), with reference-based cleanup of unused contacts.

No public release has been tagged or published yet.
