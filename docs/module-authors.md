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
3. Queue work with `Api\QueueInterface::publish('my_notification', $reference)`.

`Delivery::$useCooldown = false` for notifications the recipient asked for (e.g. back in stock): they are always
sent and do not start the cooldown. `Delivery::$stoppable = false` for messages the recipient cannot stop with Do not
contact (orders, payments); a feature's marketing is stoppable (the default). A stoppable send without an
unsubscribe link of its own gets the stop link as its one-click URL. Marketing templates show the stop link with
`{{depend stop_url}}…{{var stop_url}}…{{/depend}}`, the URL from
`Api\StopListInterface::getStopUrl(Channel::EMAIL, $email, $storeId)` ('' when the shop turned it off).
`SenderInterface::send()` returns `SENT`, `COOLDOWN`, `RECIPIENT_CHANGED`, `STOPPED` or `QUIET_HOURS`; on anything
but `SENT`, undo your own claim. `QUIET_HOURS` means held, not dropped: a stoppable delivery in the store's quiet hours
for its channel; nothing was sent or claimed. Send it again at `QuietHoursInterface::nextAllowedAt($storeId, $channel)`
(UTC), keeping your own retry state (it is not a failure); a finder can leave out a store while `isQuiet()`.
Deliveries with `$stoppable = false` are never held. Replace the cooldown with a `CooldownInterface` preference, the
quiet hours with a `QuietHoursInterface` preference (e.g. one that uses the recipient's time zone).

## Providers

A provider delivers notifications on one channel: `Api\Channel::EMAIL`, or a code your module chooses (e.g. `sms`);
the admin selects the email provider (**Email provider**, default *Magento email*). A provider module needs three things:

1. A class implementing `Api\ProviderInterface`: `getCode()`, `getLabel()`, `getChannel()`,
   `supportsOneClickUnsubscribe()` and `send(Delivery, DeliveryContent)`. Throw if the service did not accept the
   notification; throw `Exception\MessageSentException` if it failed after accepting it.
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

The sender keeps the cooldown, recipient re-check, stop list and store environment for every provider. A non-email
channel brings its normalizer (below), its subscription types (with their own permission if the channel needs a
separate opt-in, see [Permission](#permission)) and its content (`DeliveryContent::$text`).

### Build an SMS provider

1. A module that requires `commercekings/module-notification` and loads after `CommerceKings_Notification`.
2. A class implementing `Api\ProviderInterface` with `getChannel()` returning your channel code, e.g. `'sms'`; `send()` posts
   `$delivery->recipient` and `$content->text` to your service and throws when it is not accepted.
3. Its item in `Model\ProviderPool` (`etc/di.xml`, as above) and its settings (credentials with Magento's
   `Encrypted` backend model) in its own `system.xml` group.
4. A `Api\RecipientNormalizerInterface` for the channel in `Model\RecipientNormalizerPool` (argument `normalizers`,
   item `sms`), e.g. to E.164 (`+381601234567`), returning `''` for something that is not a number: contacts, the
   cooldown, the stop list and your provider then see one form of each number. It is required for sign-ups on the
   channel; without one the channel takes no sign-ups and sends only trim the number.
5. Notification types send with `Delivery::text($storeId, 'sms', $phone, fn () => new DeliveryContent([], text: '...'))`.
6. **Stop replies:** when someone replies STOP, your inbound webhook calls
   `Api\StopListInterface::stop('sms', $from)`. Stoppable sends to that number are dropped from then on, and its
   stoppable subscriptions are removed. A stop link for texts is `getStopUrl('sms', $phone, $storeId)` – long
   (~200 characters), so most SMS use "Reply STOP to stop" instead.

With one SMS provider installed it is used automatically; to choose between several, set
`commercekings_notification/general/provider_sms` to the provider's code (your module may add the field).
Any other channel works the same way: pick a code (e.g. `whatsapp`) and use it in both places.

## Permission

Who may subscribe is `Api\SubscriptionPermissionInterface`: `check($type, $target)` returns a `Permission`
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
`Model\TypePermission` / `Model\TypeSubscriber` (argument `typeCode`), so your code follows the type.

## Subscription types

For notifications a visitor signs up for, also:

1. Implement `Api\SubscriptionTypeInterface`:
   - `getPurpose()`: what the subscription is for (with the consent bridge: the Consent purpose your module declares);
   - `getChannel()`: the channel its subscribers are reached on – `Channel::EMAIL`, or your provider's code (e.g.
     `sms`). Guests subscribe with `recipient` (REST `{"type", "reference", "recipient"}`): an email address, or e.g.
     a phone number; it is stored as a contact on that channel. An address the channel does not take is Magento's 400.
     Signed-in customers (`subscribeCustomer()`, REST `/mine`) use the account email on email types; on other
     channels they pass `recipient` too (required), stored as their contact next to the customer id;
   - `isSingle()`: one subscription per reference (a new email replaces it) or one per person;
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
4. Keep your send history keyed by what "once" means for you (e.g. the cart), never by the subscription id:
   a withdrawal deletes the subscription, and subscribing again creates a new one.
5. To react to a sign-up – e.g. an SMS opt-in confirmation ("Reply YES to confirm") – observe
   `commercekings_notification_subscription_saved` (`SubscriptionManagementInterface::EVENT_SAVED`). It is dispatched
   after the row is committed, with `subscription_id`, `type`, `channel`, `store_id`, `contact_id`, `customer_id` and
   `created` (true for a new row; false when an existing one changed, e.g. a new number or permission); not for a
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
