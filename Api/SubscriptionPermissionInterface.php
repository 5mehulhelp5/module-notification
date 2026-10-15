<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;

/**
 * May the current visitor subscribe, and is a stored subscription still allowed? Replace it through a DI
 * preference (e.g. with a consent module) to put a requirement on subscriptions.
 *
 * @api
 */
interface SubscriptionPermissionInterface
{
    /** Called after the type authorized the target and before anything is written. */
    public function check(SubscriptionTypeInterface $type, Target $target): Permission;

    /**
     * SQL condition: the permission reference in $referenceColumn is still valid. It is part of the subscribe
     * INSERT and of every send-time check (SubscriptionGuardInterface), so a revocation at the same moment wins.
     * @param string $referenceColumn trusted SQL identifier/expression or an adapter-quoted value
     */
    public function validCondition(string $referenceColumn): string;

    /**
     * Can isCurrentVisitor() tell who gave a permission? A feature hands a visitor something the subscriber owns
     * (e.g. a saved cart) only when it can.
     */
    public function identifiesVisitor(): bool;

    /** Is the current visitor the one who gave this permission? false when it cannot tell. */
    public function isCurrentVisitor(string $reference): bool;
}
