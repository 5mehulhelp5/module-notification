<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;

/**
 * One type's permission as a SubscriptionPermissionInterface: its own (getPermission()) or the shop-wide one. A feature
 * injects it for its type with a di.xml virtual type (argument "typeCode"), e.g. where it claims and sends.
 */
class TypePermission implements SubscriptionPermissionInterface
{
    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly string $typeCode
    ) {
    }

    public function check(SubscriptionTypeInterface $type, Target $target): Permission
    {
        return $this->resolver->forType($type)->check($type, $target);
    }

    public function validCondition(string $referenceColumn): string
    {
        return $this->permission()->validCondition($referenceColumn);
    }

    public function isValidFor(string $reference, string $purpose, int $storeId): bool
    {
        return $this->permission()->isValidFor($reference, $purpose, $storeId);
    }

    public function isOptOut(): bool
    {
        return $this->permission()->isOptOut();
    }

    /** Resolved on use: the type pool may still be built when this is created. */
    private function permission(): SubscriptionPermissionInterface
    {
        return $this->resolver->forCode($this->typeCode);
    }
}
