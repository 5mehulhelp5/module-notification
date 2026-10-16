<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Cron;

use CommerceKings\Notification\Model\ContactReferencePool;
use CommerceKings\Notification\Model\ContactRepository;
use Magento\Framework\App\ResourceConnection;

/**
 * Remove contacts no registered module uses any more (e.g. a guest retyped the email). A contact is kept
 * for a day first. Guest capture holds the contact row lock until its subscription commits, so cleanup
 * cannot remove an old contact while it is being reused. Without registered references nothing is removed.
 */
class CleanUpContacts
{
    private const GRACE_HOURS = 24;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ContactReferencePool $references
    ) {
    }

    public function execute(): int
    {
        $used = array_map(fn ($reference) => '(' . $reference->usedCondition('n.contact_id') . ')', $this->references->getAll());
        if (!$used) {
            return 0;
        }
        $db = $this->resource->getConnection();
        return $db->query(sprintf('DELETE n FROM %s AS n WHERE n.created_at < NOW() - INTERVAL %d HOUR AND NOT (%s)',
            $db->quoteIdentifier($this->resource->getTableName(ContactRepository::TABLE)), self::GRACE_HOURS,
            implode(' OR ', $used)))->rowCount();
    }
}
