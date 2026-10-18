<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;

/**
 * Who may subscribe to a type: its own permission (SubscriptionTypeInterface::getPermission(), e.g. an SMS checkbox),
 * else the shop-wide one (the SubscriptionPermissionInterface preference: opt-out, or the consent bridge).
 */
class PermissionResolver
{
    public function __construct(
        private readonly SubscriptionPermissionInterface $shopWide,
        private readonly SubscriptionTypePool $types
    ) {
    }

    public function forType(SubscriptionTypeInterface $type): SubscriptionPermissionInterface
    {
        return $type->getPermission() ?? $this->shopWide;
    }

    public function forCode(string $typeCode): SubscriptionPermissionInterface
    {
        $type = $this->types->get($typeCode);
        return $type !== null ? $this->forType($type) : $this->shopWide;
    }

    /**
     * SQL: the subscription's permission is valid, by its own type's rule. Types with their own permission are checked
     * by it; every other type (and an unknown one) by the shop-wide permission.
     *
     * @param string $permissionRefColumn trusted SQL identifier of the subscription's permission_ref
     * @param string $typeColumn trusted SQL identifier of the subscription's type
     */
    public function validCondition(string $permissionRefColumn, string $typeColumn): string
    {
        $own = [];
        foreach ($this->types->getAll() as $code => $type) {
            $permission = $type->getPermission();
            if ($permission !== null) {
                $own[(string)$code] = $permission;
            }
        }
        $shopWide = $this->shopWide->validCondition($permissionRefColumn);
        if ($own === []) {
            return $shopWide;
        }
        $quote = fn (string $code) => "'" . addcslashes($code, "'\\") . "'";
        $branches = [];
        foreach ($own as $code => $permission) {
            $branches[] = sprintf('(%s = %s AND (%s))', $typeColumn, $quote($code), $permission->validCondition($permissionRefColumn));
        }
        $branches[] = sprintf('(%s NOT IN (%s) AND (%s))', $typeColumn, implode(', ', array_map($quote, array_keys($own))), $shopWide);
        return '(' . implode(' OR ', $branches) . ')';
    }
}
