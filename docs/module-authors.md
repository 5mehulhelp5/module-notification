# For module authors

Building a notification type on `commercekings/module-notification`. Start with the [README](../README.md).

## Notification types

1. Implement `Api\NotificationTypeInterface::process(string $reference)`: re-check, claim atomically,
   then `Api\MailerInterface::send(new Message(...))`.
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

`Message::$useCooldown = false` for emails the recipient asked for (e.g. back in stock): they are always
sent and do not start the cooldown. `MailerInterface::send()` returns `SENT`, `COOLDOWN` or
`RECIPIENT_CHANGED`; on anything but `SENT`, undo your own claim. Replace the cooldown with a
`CooldownInterface` preference.

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

## Subscription types

For notifications a visitor signs up for, also:

1. Implement `Api\SubscriptionTypeInterface`:
   - `getPurpose()`: what the subscription is for (with the consent bridge: the Consent purpose your module declares);
   - `isSingle()`: one subscription per reference (a new email replaces it) or one per person;
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
   contact's address or the customer's current one). Repeat the check inside your atomic claim: without the
   subscription table in the UPDATE, add `Api\SubscriptionGuardInterface::validCondition('<subscription id>')`;
   with it joined (e.g. `… JOIN ck_notification_subscription AS s`), check the same row (`s.subscription_id = ?`)
   and `Api\SubscriptionPermissionInterface::validCondition('s.permission_ref')` instead - MySQL does
   not allow a subquery on a table the UPDATE itself joins (error 1093).
4. Keep your send history keyed by what "once" means for you (e.g. the cart), never by the subscription id:
   a withdrawal deletes the subscription, and subscribing again creates a new one.

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
