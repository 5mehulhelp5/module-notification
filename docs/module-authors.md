# For module authors

Building a notification type on `commercekings/module-notification`. Start with the [README](../README.md).

## Notification types

1. Implement `Api\NotificationTypeInterface::process(string $reference)`: re-check, claim atomically,
   then `Api\SenderInterface::send(new Delivery(...))`.
2. Register it in your `etc/di.xml`:
   ```xml
   <type name="CommerceKings\Notification\Model\TypePool">
       <arguments>
           <argument name="types" xsi:type="array">
               <item name="my_notification" xsi:type="object">Vendor\Module\Model\MyNotification</item>
           </argument>
       </arguments>
   </type>
   ```
   The `types` argument of `Model\TypePool` is `@api`: a stable extension point.
3. Queue work with `Api\QueueInterface::publish('my_notification', $reference)`.

`Delivery::$useCooldown = false` for notifications the recipient asked for (e.g. back in stock): they are always
sent and do not start the cooldown. `Delivery::$stoppable = false` for messages the recipient cannot stop with Do not
contact (orders, payments); a feature's marketing is stoppable (the default). A stoppable send without an
unsubscribe link of its own gets the stop link as its one-click URL. Marketing templates show the stop link with
`{{depend stop_url}}…{{var stop_url}}…{{/depend}}`, the URL from
`Api\StopListInterface::getStopUrl(Channel::EMAIL, $email, $storeId)` ('' when the shop turned it off).
`SenderInterface::send()` returns `SENT`, `COOLDOWN`, `RECIPIENT_CHANGED`, `STOPPED` or `QUIET_HOURS`; on anything
but `SENT`, undo your own claim. `QUIET_HOURS` means held, not dropped: a stoppable delivery in quiet hours for its
channel and recipient; nothing was sent or claimed. Send it again at
`QuietHoursInterface::nextAllowedAt($storeId, $channel, $recipient)` (UTC), keeping your own retry state (it is not a
failure). A finder can leave out a store while `isQuiet($storeId, $channel)` – without a recipient it asks whether
everyone is held, and a per-recipient rule answers no. Deliveries with `$stoppable = false` are never held. Pass your
notification type code as `Delivery::$type` (e.g. `type: 'cart_reminder'`), so providers with templates of their own
can pick one. A finder leaves out a recipient in cooldown before queuing with
`CooldownInterface::isRecentFor($channel, $recipient)` (the key the sender claims). Replace the cooldown with a
`CooldownInterface` preference; for quiet hours in the recipient's time zone
register a zone provider for the channel ([`QuietHoursZoneInterface`](#quiet-hours-in-the-recipients-time-zone)).

## Providers

A provider delivers notifications on one channel: `Api\Channel::EMAIL`, `Channel::SMS`, `Channel::WHATSAPP`,
`Channel::VIBER`, or a
code your module chooses. The admin selects one provider per channel (*Delivery* group); the email one is
**Email provider**, default *Magento email*. A provider module needs:

1. A class implementing `Api\ProviderInterface`: `getCode()`, `getLabel()`, `getChannel()`,
   `supportsOneClickUnsubscribe()` and `send(Delivery, DeliveryContent)`. Throw if the service did not accept the
   notification; throw `Exception\MessageSentException` if it failed after accepting it, or when you cannot tell
   (see [Build an SMS, WhatsApp or Viber provider](#build-an-sms-whatsapp-or-viber-provider)).
2. Its item in `Model\ProviderPool` (`etc/di.xml`):
   ```xml
   <type name="CommerceKings\Notification\Model\ProviderPool">
       <arguments>
           <argument name="providers" xsi:type="array">
               <item name="my_provider" xsi:type="object">Vendor\Module\Model\MyProvider</item>
           </argument>
       </arguments>
   </type>
   ```
3. Its own settings group in `etc/adminhtml/system.xml`, in the `commercekings_notification` section.

The sender keeps the cooldown, recipient re-check, stop list, quiet hours and store environment for every provider.

### The provider selector

With one provider installed for a channel it is used automatically. Install one provider module per channel: recipient
time zones (`QuietHours` "zones") and opt-in confirmations are per module. With several and none selected, the first
installed (module load order) is used and a warning is logged once per process – select one under *Delivery*, since a
deployment can change that order. To choose between several, the stored code is
`commercekings_notification/general/provider_<channel>` (read per store view, e.g. set per website). The first module
for a channel adds the field to the *Delivery* group, with `Model\Config\Source\Provider` for its channel (`@api`: a
virtual type with its `channel` argument is a stable extension point):

```xml
<!-- etc/di.xml -->
<virtualType name="VendorSmsProviderSource" type="CommerceKings\Notification\Model\Config\Source\Provider">
    <arguments>
        <argument name="channel" xsi:type="const">CommerceKings\Notification\Api\Channel::SMS</argument>
    </arguments>
</virtualType>
```

```xml
<!-- etc/adminhtml/system.xml, in section commercekings_notification -->
<group id="general">
    <field id="provider_sms" translate="label" type="select" sortOrder="6"
           showInDefault="1" showInWebsite="1" showInStore="0">
        <label>SMS provider</label>
        <source_model>VendorSmsProviderSource</source_model>
    </field>
</group>
```

Several modules may declare the same field: Magento merges them into one.

## Messaging

SMS, WhatsApp and similar channels are part of Notification: a provider module (Twilio, Infobip, Meta, …) only adds
the vendor's code. Everything below works the same for every provider of a channel.

### Channels and phone numbers

Use `Channel::SMS`, `Channel::WHATSAPP` and `Channel::VIBER`, never a code of your own for them: contacts, the stop
list, the cooldown and quiet hours are per channel, so every provider of a channel shares them. Notification normalizes
both to strict E.164 (`Model\PhoneNormalizer`: `+`, a country code and 8–15 digits; spaces, dashes, dots and brackets
are left out; anything else is refused with `''`). Your provider gets `$delivery->recipient` in that form; strip your
service's own prefixes (e.g. `whatsapp:`) before you hand a number to Notification. The core normalizer owns both
channels: a provider module does not register one for `sms`, `whatsapp` or `viber` – every provider of a channel must
see the same form, and a replacement changes the keys of stored contacts and stops. Only a project replaces it, on
purpose (e.g. with a phone library for national formats), in `Model\RecipientNormalizerPool` (argument `normalizers`). A
new channel (e.g. `telegram`) brings its own normalizer – without one it takes no sign-ups.

### Build an SMS, WhatsApp or Viber provider

1. A module that requires `commercekings/module-notification` and loads after `CommerceKings_Notification`.
2. A provider (above) with `getChannel()` returning `Channel::SMS`, `Channel::WHATSAPP` or `Channel::VIBER`; one module
   may register several. `send()` posts `$delivery->recipient` and `$content->text` (or a template, below) and throws
   when the service does not accept it. It runs in the store's environment: read your store-scoped settings (credentials
   with Magento's `Encrypted` backend model, a sender id or messaging service) for `$delivery->storeId`.
3. Notification types send with `Delivery::text($storeId, Channel::SMS, $phone, fn () => new DeliveryContent([],
   text: '...'), type: 'my_type')`.
4. Hash or sign anything you store about a recipient with `Model\KeyedHash`, so it survives `encryption:key:change`.
5. Keep your service's own channel failover off (e.g. Viber or WhatsApp falling back to SMS): the message would
   arrive on a channel whose stop list, consent and quiet hours Notification never checked. A feature that wants a
   fallback sends a second delivery on the other channel.
6. A timeout or lost connection after the request may have left: throw `Exception\MessageSentException`, not a plain
   error – the service may have accepted it, and a retry could deliver it twice (a missed reminder is better than a
   duplicate). Treat a failure as "not sent" only when you can prove the request never left (host not found,
   connection refused, TLS handshake, a timeout before any transfer): cURL reports a timeout (28) while connecting and
   while waiting for the answer alike.
7. Keep texts short: an SMS segment holds 160 GSM-7 characters, or 70 when any character needs Unicode (an accent
   outside GSM-7, an emoji); longer texts are sent and billed as several segments of 153 / 67. Name your brand and
   end with "Reply STOP to stop"; a long link costs segments, so prefer a short one.

### Templates (e.g. WhatsApp)

A channel that only sends approved templates (a WhatsApp template, a Twilio Content SID) keeps the mapping in the
provider module, not in the feature: a setting per notification type and locale – `$delivery->type` and the store's
locale (`general/locale/code` for `$delivery->storeId`) – gives the template, and its placeholders name the variables
to fill from `DeliveryContent::$vars` (plain text only, e.g. `customer_name`, `restore_url`; leave out HTML such as
`items_html`). `Delivery::$templateId` is the channel's template when the feature sets one. A delivery of an unknown
type (`''`) or without a mapping cannot be sent: throw, so the sender releases the cooldown claim and the feature
counts a failed attempt.

### Webhooks: replies and delivery status

- **Verify the signature first** (e.g. Twilio's `X-Twilio-Signature`) against the store's configured public URL, not
  the request's (a proxy changes it), and answer 403 without doing anything else when it fails. The controller
  implements `CsrfAwareActionInterface`.
- **One webhook URL for several stores:** the service posts without a store or website. Identify the account by the
  request – try the signature with each configured account's credentials (per website) and use the one that matches;
  its scope tells you the website. Refuse a request no account signs.
- **Normalize the sender** before anything else: strip the service's prefixes (e.g. `whatsapp:`) and add a missing
  `+` (some services, e.g. Infobip, send `381601234567`), so `$from` is E.164 like the stored contacts. Every
  `StopListInterface` method (`stop()`, `resume()`, `touch()`, `isStopped()`, `getReason()`, `getStopUrl()`) throws
  `\InvalidArgumentException` for an address the channel cannot take: catch it, log it without the number and answer
  200, so the service does not retry a request that can never succeed.
- **Do not register an inbound (reply) webhook in module-rate-limit:** a limited request is answered 429 and the
  service does not send it again – a STOP would be lost. The signature check is its protection. A delivery status
  callback may be limited.
- **STOP:** a reply that is exactly an opt-out keyword – STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT, OPTOUT,
  REVOKE – ignoring case, spaces and punctuation (no first-word matching), or a WhatsApp "Stop promotions" button:
  `Api\StopListInterface::stop($channel, $from, StopListInterface::REASON_REPLY)`. Stoppable deliveries to the number
  are dropped from then on, in every website, its stoppable subscriptions and checkbox consents removed, and the stop
  holds until START (no lift timer; the stop link's Undo cannot lift it). Service messages still go out. Texts say
  "Reply STOP to stop" – a stop link (`getStopUrl()`) works too but is long (~200 characters).
- **HELP** (INFO): leave it to the service's own auto-reply (set it up with your brand and how to stop); there is
  nothing for Notification to do.
- **START** (UNSTOP, YES – the whole reply, as for STOP): `resume($channel, $from)`. It lifts the stop only – the
  removed subscriptions do not come back; the recipient signs up again (with consent).
- **Undeliverable numbers** (invalid, a landline, not reachable): when the service refuses at once, `send()` throws, so
  the feature counts a failed attempt and gives up after its retries. When it reports later (a status callback, e.g.
  Twilio 30003/30005/30006, 21211), call `stop($channel, $to, StopListInterface::REASON_UNDELIVERABLE)`: the number is
  suppressed and keeps its consents, but its stoppable subscriptions are removed as with any stop – when the stop lifts
  after the configured days, the shopper signs up again (the kept consent is reused). A number stopped at the carrier
  (e.g. Twilio 21610) is an opt-out: `stop(..., REASON_REPLY)`, and throw when it was refused at once. Keep personal
  data out of your delivery log.

### Opt-in confirmation

Some rules (e.g. US carriers for 10DLC) want a confirmation text after a sign-up. Observe
`commercekings_notification_subscription_saved` (`SubscriptionManagementInterface::EVENT_SAVED`) for your channel and
send one only when `first_for_address` is true – the number currently has no other subscription of any type in the
website, so a new cart of a known number gets none (once all its subscriptions are gone, e.g. after a stop, the next
sign-up confirms again). The confirmation informs; it is not a double opt-in – a subscription is active once it is
stored, and Notification does not wait for a "YES" reply. It is read without a lock: two sign-ups for one new number at
the same moment can both see true. Send once with a claim on the delivery state, keyed by the contact, and send only
when it changed a row:

```php
$state->ensure('my_optin_confirmation', $contactId);
$first = $db->update($state->getTableName(), ['sent_at' => new \Zend_Db_Expr('NOW()')],
    $state->keyCondition('my_optin_confirmation', $contactId) + ['sent_at IS NULL']) === 1;
```

The claim holds until you delete the row: delete it when the number should be confirmed again (e.g. after a stop).
Notification's daily cleanup removes delivery state only for subscription types, so remove these rows yourself (e.g.
a cron deleting rows whose contact no longer exists in `Api\ContactRepositoryInterface::TABLE`). Queue it rather
than sending
from the request: a `NotificationTypeInterface` of your own in `Model\TypePool`, published with
`Api\QueueInterface::publish('my_optin_confirmation', (string)$subscriptionId)`; its `process()` reads the
subscription (`SubscriptionRepositoryInterface::get()`, `getRecipient()`) and sends with `useCooldown: false` (the
confirmation must not hold back the first real message) and `stoppable: false` (a service message the recipient asked
for: never held or stopped). Limit how many confirmations one number gets – a sign-up form that texts any number is a
target for SMS pumping.

### Quiet hours in the recipient's time zone

The store's quiet hours (*Quiet hours*: from – to, channels; on by default, 20:00–08:00 for every channel except
email) are kept in the store's time zone. US state laws such as
Florida's FTSA go by the recipient's local time: register a zone provider for your channel instead of replacing
`QuietHoursInterface` – Notification keeps the settings, the window and clock changes:

```xml
<type name="CommerceKings\Notification\Model\QuietHours">
    <arguments>
        <argument name="zones" xsi:type="array">
            <item name="sms" xsi:type="object">Vendor\Module\Model\NumberZones</item>
        </argument>
    </arguments>
</type>
```

The `zones` argument of `Model\QuietHours` is `@api`: a stable extension point.

`Api\QuietHoursZoneInterface::getZones($storeId, $channel, $recipient)` returns the zones the number may be in (e.g.
from its country and area code): quiet while the window is on in any of them, until the latest end. Return `[]` when
you cannot tell: for a recipient, Notification then keeps the store's time zone, so a number of unknown zone is still
quiet at night. Return `[]` for a `null` recipient too (the whole store, asked by a finder before it knows the
recipients): that means not quiet store-wide, and the sender holds each delivery on its own.

### Consent for an address

A messaging type usually needs its own consent for the number (a checkbox): see [Address consent](#address-consent).

## Permission

Who may subscribe is `Api\SubscriptionPermissionInterface`: `check($type, $target, $recipient)` returns a `Permission`
(granted with a reference stored on the subscription, absent – a guest's single subscription is removed – or
refused – nothing changes), and `validCondition($referenceColumn)` is the SQL that the subscribe INSERT and every
send-time check (`SubscriptionGuardInterface`) contain, so a revocation at the same moment wins.
`isValidFor($reference, $purpose, $storeId)` rechecks a stored reference at send time.

The subscriber behind a permission is `Api\SubscriberInterface`, replaced together with the permission:
`identifiesVisitor()` / `isCurrentVisitor($reference)` say whether the current visitor is the one who gave a
permission (e.g. Consent compares the browser the consent was given in) – a feature hands over something the
subscriber owns only when it can tell – and `getUnsubscribeUrl($subscription)` is its unsubscribe link (null when
there is none). Defaults: `Model\OpenPermission` and `Model\AnonymousSubscriber`.
When a permission is revoked or the shopper unsubscribes,
call `Api\SubscriptionRemovalInterface` (`removeByPermission($references)`, `remove($subscriptionId)`).
In the storefront, ask `CommerceKings_Notification/js/permission` (`hasPermission(purpose)`,
`onChange(purpose, callback)`) before subscribing; a permission module maps it to its own with requirejs `map`.

`Model\OpenPermission` allows every authorized subscription until it is removed (opt-out).
`commercekings/module-consent-notification` replaces both defaults with CommerceKings_Consent: a subscription needs a
valid consent for the type's purpose, withdrawing that consent removes it, the subscriber is the browser the consent
was given in, and the unsubscribe link is Consent's signed withdrawal page.

That shop-wide permission applies to every type, unless a type brings its own: `getPermission()` on the type (below)
returns its own `SubscriptionPermissionInterface` – e.g. an SMS offer with its own "text me offers" checkbox – and
that one alone decides for its subscriptions, at sign-up and at send time. `null` keeps the shop-wide one. Its
subscriber comes with it: `getSubscriber()` returns the matching `SubscriberInterface` (recognizing the visitor, the
unsubscribe link), `null` the shop-wide one. Inject either for your type with a di.xml virtual type of
`Model\TypePermission` / `Model\TypeSubscriber` (argument `typeCode`), so your code follows the type. Both virtual
types are `@api`: stable extension points.

## Subscription types

For notifications a visitor signs up for, also:

1. Implement `Api\SubscriptionTypeInterface`:
   - `getPurpose()`: what the subscription is for (with the consent bridge: the Consent purpose your module declares);
   - `getChannel()`: the channel its subscribers are reached on – `Channel::EMAIL`, `Channel::SMS` or
     `Channel::WHATSAPP`, `Channel::VIBER`; a code of your own only for another channel. Guests subscribe with
     `recipient` (REST `{"type", "reference", "recipient"}`): an email address, or e.g. a phone number; it is stored as
     a contact on that channel. An address the channel does not take is Magento's 400. Signed-in customers
     (`subscribeCustomer()`, REST `/mine`) use the account email on email types; on other channels they pass `recipient`
     too (required), stored as their contact next to the customer id;
   - `isSingle()`: one subscription per reference (a new address replaces it) or one per person;
   - `getPermission()` and `getSubscriber()`: your type's own permission and subscriber, or `null` for the shop-wide
     ones (see [Permission](#permission));
   - `isStoppable()`: can the recipient stop it with Do not contact? true for marketing (a stop removes the
     subscription; pass the same value as `Delivery::$stoppable`), false for a service they asked for (a stop leaves
     it);
   - `authorize($reference, $customerId)`: `null` when the visitor may not touch it, else a `Data\Target`
     with your id, the store, whether it accepts subscriptions now, and an optional trusted SQL condition
     repeated in the write (e.g. "the cart is still active in this store");
   - `orphanCondition($referenceIdColumn)`: SQL true once your item is gone (e.g. Magento deleted the cart).
     Notification has no foreign key to your table; this is how a deleted item's subscriptions, and then the
     guest's address, are removed.
2. Register it under the same code:
   ```xml
   <type name="CommerceKings\Notification\Model\SubscriptionTypePool">
       <arguments>
           <argument name="types" xsi:type="array">
               <item name="my_notification" xsi:type="object">Vendor\Module\Model\MySubscriptionType</item>
           </argument>
       </arguments>
   </type>
   ```
   The `types` argument of `Model\SubscriptionTypePool` is `@api`: a stable extension point.
   With the consent bridge, withdrawing your purpose's consent removes the subscriptions; nothing else to register.
3. Queue the **subscription id**; read it with `Api\SubscriptionRepositoryInterface` (`getEmail()` gives the
   contact's address or the customer's current one; `getRecipient()` the channel and address for guests on any channel). Repeat the check inside your atomic claim: without the
   subscription table in the UPDATE, add `Api\SubscriptionGuardInterface::validCondition('<subscription id>')`;
   with it joined (e.g. `… JOIN ck_notification_subscription AS s`), check the same row (`s.subscription_id = ?`)
   and `Api\SubscriptionPermissionInterface::validCondition('s.permission_ref')` instead – MySQL does
   not allow a subquery on a table the UPDATE itself joins (error 1093). Inject that permission as your type's,
   so it follows `getPermission()`:
   ```xml
   <virtualType name="MyNotificationPermission" type="CommerceKings\Notification\Model\TypePermission">
       <arguments>
           <argument name="typeCode" xsi:type="string">my_notification</argument>
       </arguments>
   </virtualType>
   ```
4. Keep your send history per subscription key, not per subscription id: a withdrawal deletes the subscription,
   and subscribing again creates a new one – "already sent" must survive that. `Api\DeliveryStateInterface` does it
   for you: one row per type, reference and subscriber (`subject_key`: `''` for a single type, so one per reference –
   e.g. per cart), with `queued_at`, `sent_at`, `skip_until`, `failed_sends` and `delivery_version`. Claim with one
   atomic UPDATE that joins it to the subscription (`joinCondition('d', 's')`) and names the key
   (`keyCondition($type, $referenceId, $subjectKey, 'd')`); `ensure()` the row first. Notification only stores it –
   what "due" means (once, again after N days, a retry backoff, an hourly cap) is yours; filter by your `type` in
   statements of your own (the table is shared). Rows go with their reference (your `orphanCondition()`, daily); a
   person's rows also go `DeliveryStateInterface::RETENTION_DAYS` (90) after their last change once no subscription
   has their key. That cleanup covers subscription types only: state you keep for another type (e.g. an opt-in
   confirmation keyed by contact id) is yours to remove.
5. **Own consent per address:** `SubscriptionPermissionInterface::check($type, $target, $recipient)` gets the
   address the subscription will be stored with, in the sender's form (normalized; email trimmed) – the guest's
   address, the customer's account email on email types, the number a customer gave on other channels. A type's own
   permission (e.g. an SMS or WhatsApp checkbox, consent per number) records its evidence through its own endpoint
   and looks it up by the target and this recipient: a consent recorded for one number grants only that number, and
   a new number gets a fresh check. Put the number into the permission reference if the send-time check
   (`validCondition()`, `isValidFor()`) must follow it too.
6. To react to a sign-up – e.g. an SMS opt-in confirmation – observe
   `commercekings_notification_subscription_saved` (`SubscriptionManagementInterface::EVENT_SAVED`). It is dispatched
   after the row is committed, with `subscription_id`, `type`, `channel`, `store_id`, `contact_id`, `customer_id` and
   `created` (true for a new row; false when an existing one changed, e.g. a new number or permission) and
   `previous_contact_id` (the changed row's contact before; differs from `contact_id` for a new address) and
   `first_for_address` (the address currently has no other subscription of any type in the website; read without a
   lock, so concurrent sign-ups can both see true – dedupe as in [Opt-in confirmation](#opt-in-confirmation)); not for a
   refusal, a stopped recipient or an identical retry. Read the address with
   `SubscriptionRepositoryInterface::get()` and `getRecipient()`. Your observer's error is logged (its class only)
   and never fails the sign-up – send or queue from it, but do not rely on it to block one.


With the consent bridge, your type's purpose needs a Consent purpose declaration. Declare it in your `di.xml` on
`CommerceKings\Consent\Model\PurposePool` (inert without Consent) and make it active only with the bridge, so a
banner installed for other reasons does not show your switch in the opt-out setup:

```xml
<item name="my_purpose" xsi:type="array">
    <item name="label" xsi:type="string">My notification</item>
    <item name="terms_revision" xsi:type="string">1</item>
    <item name="active_if" xsi:type="array">
        <item name="consent_bridge" xsi:type="string">commercekings_consent_notification/general/enabled</item>
    </item>
</item>
```

### Address consent

A messaging type usually needs a consent for the number itself – a checkbox next to the phone field ("Yes, text me
offers at this number"). Notification ships it; your type only declares it:

1. A virtual type of `Model\AddressConsent` for your type code, injected into your type and returned by its
   `getPermission()`:
   ```xml
   <virtualType name="MySmsOfferConsent" type="CommerceKings\Notification\Model\AddressConsent">
       <arguments>
           <argument name="typeCode" xsi:type="string">my_sms_offer</argument>
       </arguments>
   </virtualType>
   <type name="Vendor\Module\Model\MySmsOfferType">
       <arguments>
           <argument name="permission" xsi:type="object">MySmsOfferConsent</argument>
       </arguments>
   </type>
   ```
   Use the same virtual type wherever your feature claims or rechecks (instead of `TypePermission`). The virtual type
   with `typeCode` and `ViewModel\AddressConsent` are `@api`: stable extension points.
2. The checkbox: add `CommerceKings_Notification::address-consent.phtml` next to the phone field, with `view_model`
   (`CommerceKings\Notification\ViewModel\AddressConsent`), `type`, `channel` (e.g. `sms`) and `phone` (a CSS selector
   of the phone input); `reference` defaults to the checkout's cart and `mine` to whether the checkout customer is
   signed in. Checking records the consent, unchecking withdraws it, a changed number moves it. Subscribe after the
   element triggers `ckAddressConsent:recorded`. The text is *Notifications > Consent for an address* per store view and
   channel (empty: no checkbox), read from `commercekings_notification/address_consent/wording_<channel>`: `sms`,
   `whatsapp` and `viber` have their fields; for a module's own channel (e.g. `telegram`) the provider module adds
   `wording_<channel>` to that group in its own `system.xml`, with a default in its `config.xml`. Only the Luma checkout
   is supported; a custom checkout can build on `CommerceKings_Notification/js/address-consent-core` (`record()`) or
   the REST routes.
3. REST: `POST /V1/ck-notification/address-consents` (guest) and `/mine` (customer) with
   `{"type", "reference", "recipient", "wordingHash", "given"}`. Your type's `authorize()` decides who may record for
   the reference. `wordingHash` is sha256 of the text the visitor saw: the server stores the store view's configured
   text and refuses a different hash, so the evidence holds the exact words. `given: false` withdraws the number's
   consent for the type and website and removes the subscriptions that rested on it.

What it grants: a subscription of that type, for that exact number (normalized), in that website – another number for
the same cart gets a fresh check and is refused. Evidence per consent in `ck_notification_address_consent`: the text,
its hash, the time, the store view, a keyed hash of the IP address, and the number only as a keyed hash
(`Model\KeyedHash`, so it still matches after a key change) – enough to prove the consent for a given number and to
find it on a stop, without storing the number. A stop (`StopListInterface::stop()`, e.g. a STOP reply) withdraws all
of the number's consents, except an undeliverable stop (`REASON_UNDELIVERABLE`), which keeps them. Active consents
are kept; withdrawn ones for *Keep withdrawn consents (days)*, default 1825
(5 years), then a daily cron deletes them. A checkbox can be ticked for someone else's number: we advise an
[opt-in confirmation](#opt-in-confirmation) (on `commercekings_notification_subscription_saved` when
`first_for_address`) before marketing – Notification does not enforce it; your provider or feature sends it.

## Opt-out notice

A feature can tell shoppers where they subscribe (e.g. under the checkout email field). Add a config group with two
fields, read them through `Api\OptOutNoticeInterface::getText('<section>/<group>', $storeId)` and show the text when
it is not null:

```xml
<group id="notice" translate="label" sortOrder="20" showInDefault="1" showInWebsite="1" showInStore="1">
    <label>Checkout notice</label>
    <field id="show" translate="label" type="select" sortOrder="10" showInDefault="1" showInWebsite="1">
        <label>Show notice</label>
        <source_model>CommerceKings\Notification\Model\Config\Source\NoticeMode</source_model>
    </field>
    <field id="text" translate="label" type="textarea" sortOrder="20" showInDefault="1" showInWebsite="1" showInStore="1">
        <label>Notice text</label>
        <depends><field id="show" separator=",">auto,1</field></depends>
    </field>
</group>
```

Default `show` to `auto` and `text` to your wording in `etc/config.xml`. *Automatic* shows the notice while
`SubscriptionPermissionInterface::isOptOut()` is true (Notification alone) and hides it with the consent bridge.
