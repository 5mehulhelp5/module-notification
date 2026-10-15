<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\SubscriptionRemovalInterface;
use Magento\Framework\App\ResourceConnection;

class SubscriptionRemoval implements SubscriptionRemovalInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function removeByPermission(array $references): int
    {
        if (!$references) {
            return 0;
        }
        return $this->resource->getConnection()->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
            ['consent_id IN (?)' => array_values($references)]);
    }

    public function remove(int $subscriptionId): bool
    {
        return $this->resource->getConnection()->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
            ['subscription_id = ?' => $subscriptionId]) > 0;
    }
}
