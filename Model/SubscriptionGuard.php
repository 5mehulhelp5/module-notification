<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\SubscriptionGuardInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use Magento\Framework\App\ResourceConnection;

/** A subscription is valid while its row exists and its permission (SubscriptionPermissionInterface) is still valid. */
class SubscriptionGuard implements SubscriptionGuardInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubscriptionPermissionInterface $permission
    ) {
    }

    public function validCondition(string $subscriptionIdColumn): string
    {
        return sprintf('EXISTS (SELECT 1 FROM %s AS ck_ns WHERE ck_ns.subscription_id = %s AND %s)',
            $this->resource->getConnection()->quoteIdentifier($this->resource->getTableName(SubscriptionManagement::TABLE)),
            $subscriptionIdColumn, $this->permission->validCondition('ck_ns.permission_ref'));
    }

    public function isValid(int $subscriptionId, string $type): bool
    {
        $db = $this->resource->getConnection();
        return (bool)$db->fetchOne($db->select()
            ->from(['ck_ns' => $this->resource->getTableName(SubscriptionManagement::TABLE)], 'subscription_id')
            ->where('ck_ns.subscription_id = ?', $subscriptionId)->where('ck_ns.type = ?', $type)
            ->where($this->permission->validCondition('ck_ns.permission_ref')));
    }
}
