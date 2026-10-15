# Changelog

## Unreleased

First release. Features:

- **Shared sending** for CommerceKings notifications: one queue consumer `commerceKingsNotificationSend`
  (RabbitMQ or Magento's database queue) dispatching `<type>|<reference>` to registered notification types.
- **Mailer:** store-emulated content, recipient re-check, optional RFC 8058 one-click headers;
  `MessageSentException` when a step fails after the mail server accepted the email.
- **Recipient cooldown:** optional per message (`Message::$useCooldown`) and per shop
  (*Email cooldown (days)*, default 7, 0 = off); keyed hashes only, pruned daily.
- **Subscriptions** (`ck_notification_subscription`): one subscribe endpoint for every type
  (`/V1/ck-notification/subscriptions`, `/mine` for customers) with shared email rules, the type's
  authorization, consent for its purpose (requires `commercekings/module-consent`) and one guarded write;
  one withdrawal handler for every type; the consumer only hands over valid subscriptions; a daily cleanup
  removes subscriptions whose item is gone (`orphanCondition()`).
- **Guest contacts** (`ck_notification_contact`, `ContactRepositoryInterface`) shared by notification
  features, with reference-based cleanup of unused contacts.

No public release has been tagged or published yet.
