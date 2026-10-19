<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;

/**
 * May the current visitor subscribe, and is a stored subscription still allowed? Replace it through a DI
 * preference (e.g. with a consent module) to put a requirement on subscriptions. Who the subscriber is and how they
 * unsubscribe is SubscriberInterface.
 *
 * @api
 */
interface SubscriptionPermissionInterface
{
    /**
     * Called after the type authorized the target and before anything is written – on every sign-up, so a new address
     * gets a fresh check.
     *
     * $recipient is the address the subscription will be stored with, in the form the sender uses (normalized by the
     * channel's RecipientNormalizerInterface; email: trimmed): a guest's given address, a customer's account email on
     * an email type, the recipient a customer gave on another channel; null when there is no address. A permission
     * bound to an address uses it: a checkbox consent recorded for a number grants only that number. Look up your
     * own evidence (recorded by your own endpoint) by the target and this recipient.
     */
    public function check(SubscriptionTypeInterface $type, Target $target, ?string $recipient = null): Permission;

    /**
     * SQL condition: the permission reference in $referenceColumn is still valid. It is part of the subscribe
     * INSERT and of every send-time check (SubscriptionGuardInterface), so a revocation at the same moment wins.
     * @param string $referenceColumn trusted SQL identifier/expression or an adapter-quoted value
     */
    public function validCondition(string $referenceColumn): string;

    /**
     * Send-time recheck in PHP (the SQL condition repeats it in the claim): is the stored permission still valid for
     * this purpose (SubscriptionTypeInterface::getPurpose()) and the store the notification is sent from?
     */
    public function isValidFor(string $reference, string $purpose, int $storeId): bool;

    /**
     * True when every authorized subscription is allowed without a recorded permission (opt-out). Features use it
     * for opt-out defaults, e.g. whether their notice shows by default (OptOutNoticeInterface).
     */
    public function isOptOut(): bool;
}
