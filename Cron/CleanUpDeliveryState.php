<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Cron;

use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Framework\App\ResourceConnection;

/**
 * Remove send state nothing needs: of references that no longer exist (each type's orphanCondition(), like the
 * subscription cleanup), and of a person (subject_key not '') whose key has had no subscription and no change for
 * DeliveryStateInterface::RETENTION_DAYS. A single type's state (one per reference, no personal data) stays while the
 * reference does, so "once per cart" survives a withdrawal and a new sign-up. State of a type that is no longer
 * registered is left alone: its module may only be disabled.
 */
class CleanUpDeliveryState
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubscriptionTypePool $types,
        private readonly DeliveryStateInterface $state
    ) {
    }

    public function execute(): int
    {
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->state->getTableName());
        $removed = 0;
        foreach ($this->types->getAll() as $code => $type) {
            $removed += $db->query(sprintf('DELETE d FROM %s AS d WHERE d.type = ? AND (%s)', $table,
                $type->orphanCondition('d.reference_id')), [(string)$code])->rowCount();
            $removed += $db->query(sprintf("DELETE d FROM %s AS d WHERE d.type = ? AND d.subject_key <> ''"
                . ' AND d.updated_at < NOW() - INTERVAL %d DAY AND NOT EXISTS (SELECT 1 FROM %s AS s WHERE %s)',
                $table, DeliveryStateInterface::RETENTION_DAYS,
                $db->quoteIdentifier($this->resource->getTableName(SubscriptionManagement::TABLE)),
                $this->state->joinCondition('d', 's')), [(string)$code])->rowCount();
        }
        return $removed;
    }
}
