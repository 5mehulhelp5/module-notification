<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Cron;

use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Framework\App\ResourceConnection;

/**
 * Remove subscriptions whose reference no longer exists (each type's orphanCondition(), e.g. a cart Magento's
 * quote cleanup deleted). Runs before the contact cleanup, which then removes addresses nothing uses any more.
 * Subscriptions of a type that is no longer registered are left alone: its module may only be disabled.
 */
class CleanUpSubscriptions
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubscriptionTypePool $types
    ) {
    }

    public function execute(): int
    {
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName(SubscriptionManagement::TABLE));
        $removed = 0;
        foreach ($this->types->getAll() as $code => $type) {
            $removed += $db->query(sprintf('DELETE s FROM %s AS s WHERE s.type = ? AND (%s)', $table,
                $type->orphanCondition('s.reference_id')), [(string)$code])->rowCount();
        }
        return $removed;
    }
}
