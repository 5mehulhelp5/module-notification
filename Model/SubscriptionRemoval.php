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
        // '' is "no reference" (e.g. OpenPermission) - shared by every such subscription, never a key to remove
        $references = array_values(array_filter(array_map('strval', $references), fn ($reference) => $reference !== ''));
        if (!$references) {
            return 0;
        }
        return $this->resource->getConnection()->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
            ['permission_ref IN (?)' => $references]);
    }

    public function remove(int $subscriptionId): bool
    {
        return $this->resource->getConnection()->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
            ['subscription_id = ?' => $subscriptionId]) > 0;
    }
}
