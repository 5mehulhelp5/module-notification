<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\ContactReferenceInterface;
use Magento\Framework\App\ResourceConnection;

/** A contact stays while a subscription points to it. */
class SubscriptionContactReference implements ContactReferenceInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function usedCondition(string $contactIdColumn): string
    {
        return sprintf('EXISTS (SELECT 1 FROM %s AS ck_ns_c WHERE ck_ns_c.contact_id = %s)',
            $this->resource->getConnection()->quoteIdentifier($this->resource->getTableName(SubscriptionManagement::TABLE)),
            $contactIdColumn);
    }
}
