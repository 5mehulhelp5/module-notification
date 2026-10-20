# Changelog

## 0.1.1 - unreleased

- **Address consent (privacy):** a checkbox consent is given for what the type authorized (the cart:
  `ck_notification_address_consent.grant_reference_id`), and unticking withdraws only that one. Before, unticking
  withdrew every consent of the number for the type and website - a guest who typed someone else's number could end
  that person's consent and sign-ups. A consent from before 0.1.1 (no cart on it) is withdrawn by unticking from the
  same IP; a STOP, a stop link or the stop list still ends all of a number's consents. A sign-up rests on its own
  cart's consent (or one from before 0.1.1), never on another cart's.

## 0.1.0 - 2026-10-19

First release. Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`).

- **Temporary refusals:** `Exception\TemporaryFailureException` (`@api`, `getRetryAfter()`, `parseRetryAfter()`) for a
  provider refusing for now - nothing sent, send it again soon. *Magento email* throws it for an SMTP 4xx reply (421,
  450, 451, 452: busy, throttled, greylisted; Symfony Mailer and Laminas Mail); the sender releases the cooldown claim
  and passes it on.
- **Sending:** one queue consumer `commerceKingsNotificationSend` (RabbitMQ or Magento's database queue) hands
  `<type>|<reference>` to registered notification types (`NotificationTypeInterface`, `Model\TypePool`). The sender
  (`SenderInterface`, `Data\Delivery`) checks Do not contact, quiet hours and the recipient cooldown, re-checks the
  recipient, builds the content in the delivery's store and hands it to the provider selected for the channel;
  `MessageSentException` when a step fails after the provider accepted it; a recipient the channel cannot take is an
  `InvalidArgumentException` before any claim. `Delivery::$type` names the notification
  type (templates of template-based channels), `Delivery::text()` builds a plain-text delivery.
- **Providers and channels:** `ProviderInterface` per channel (`Model\ProviderPool`; *Channels and providers > Email provider*, and
  `provider_<channel>` selectors that provider modules add). Built in: *Magento email*, with optional RFC 8058 one-click
  unsubscribe headers (also on 2.4.4–2.4.7; a replaced mail transport sends without them and logs a warning).
  `Channel::EMAIL`, `SMS`, `WHATSAPP` and `VIBER`; one recipient form per channel (`RecipientNormalizerInterface`,
  `Model\RecipientNormalizerPool`; SMS, WhatsApp and Viber numbers strict E.164 by `Model\PhoneNormalizer`).
- **Subscriptions:** `SubscriptionTypeInterface` (channel, single or per person, stoppable, own permission and
  subscriber, authorization, orphan condition), one REST sign-up for every type (`/V1/ck-notification/subscriptions`
  with `recipient`, `/mine` for customers – the account email on email types, a given recipient on other channels),
  one guarded write, and `commercekings_notification_subscription_saved` after the commit (with `created`,
  `previous_contact_id` and `first_for_address`). Guest addresses are contacts (`ck_notification_contact`, one per website, channel and
  address), removed when nothing uses them (`ContactReferenceInterface`).
- **Permissions:** `SubscriptionPermissionInterface` (`check()` with the address the subscription is stored with, a
  SQL condition for every write and send, a send-time recheck) and `SubscriberInterface` (visitor recognition, the
  unsubscribe link). Alone: opt-out (`OpenPermission`); with `commercekings/module-consent-notification`: consent.
  Per type: `Model\TypePermission`, `Model\TypeSubscriber`. `OptOutNoticeInterface` resolves a feature's notice.
- **Address consent:** a checkbox consent for one number, per type, channel and website (`Model\AddressConsent`),
  recorded through `AddressConsentManagementInterface` (REST `/V1/ck-notification/address-consents`, `/mine`) with the
  exact wording shown (*Consent for an address*, per store view and channel; `{{number}}` shows the number, while the
  configured wording is stored and hashed), the time and keyed hashes of the number and IP
  (`ck_notification_address_consent`). Unchecking or a stop withdraws it; withdrawn evidence is deleted after *Keep
  withdrawn consents (days)*, default 1825. Luma checkbox on a framework-free `js/address-consent-core`; a changed
  number unchecks it and withdraws the consent – a new number is never recorded without a new check.
- **Delivery state:** `DeliveryStateInterface` (`ck_notification_delivery_state`) – a feature's send history per
  subscription key, so it survives a withdrawal and a new sign-up; a documented table for atomic claims (also for
  sending once, e.g. an opt-in confirmation; the daily cleanup covers subscription types only).
- **Do not contact:** `StopListInterface`. A signed stop link in stoppable notifications (`/cknotification/stop`,
  confirmation and Undo; also the one-click URL) or a STOP reply stops all stoppable notifications to a recipient on a
  channel and removes those subscriptions; sign-ups for a stopped recipient are answered as stored but not written.
  Each stop has a reason (`REASON_LINK`, `REASON_REPLY`, `REASON_UNDELIVERABLE`; the strongest is kept): link and
  undeliverable stops lift after *Lifts after (days without attempts)*, default 90; a STOP reply only by `resume()`
  (START); an undeliverable number keeps its consents. `stop()` and `resume()` take the request's time (e.g. a
  reply's receive time): a START lifts only an older stop, so replies delivered out of order cannot undo a newer STOP. Stops and links survive
  `encryption:key:change` while the old key stays in `env.php` (`Model\KeyedHash`).
- **Quiet hours:** `QuietHoursInterface` and *Sending rules > Quiet hours* (per store view: from – to, may cross midnight; channels; on
  by default: 20:00–08:00 for every channel except email). Stoppable deliveries are held
  (`SenderInterface::QUIET_HOURS`) and sent after `nextAllowedAt()`. The window is kept in the store's time zone, or in
  the recipient's through a zone provider per channel (`QuietHoursZoneInterface`, `Model\QuietHours` "zones").
- **Recipient cooldown:** an optional minimum gap per recipient and channel (*Sending rules > Cooldown (days)*, default 7);
  keyed hashes only. `CooldownInterface::isRecentFor($channel, $recipient)` checks the key the sender claims, so a
  finder can leave out a recipient in cooldown on any channel before it queues anything.
- **Stable extension points (`@api`):** the interfaces in `Api\`, `Model\KeyedHash`, and the di.xml arguments and
  virtual types of `Model\ProviderPool`, `RecipientNormalizerPool`, `TypePool`, `SubscriptionTypePool`, `QuietHours`,
  `ContactReferencePool`, `TypePermission`, `TypeSubscriber`, `AddressConsent`, `Config\Source\Provider` and
  `ViewModel\AddressConsent`.

No public release has been tagged or published yet.
