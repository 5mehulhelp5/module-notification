<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/** Reads ck_notification_subscription; writes belong to SubscriptionManagement and the withdrawal handler. */
class SubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ContactRepositoryInterface $contacts,
        private readonly CustomerRepositoryInterface $customers
    ) {
    }

    public function get(int $subscriptionId): ?Subscription
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE))
            ->where('subscription_id = ?', $subscriptionId));
        return $row ? $this->toData($row) : null;
    }

    public function find(string $type, int $referenceId): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE))
            ->where('type = ?', $type)->where('reference_id = ?', $referenceId)->order('subscription_id'));
        return array_map(fn (array $row) => $this->toData($row), $rows);
    }

    public function getEmail(Subscription $subscription): ?string
    {
        if ($subscription->contactId !== null) {
            return $this->contacts->getEmail($subscription->contactId);
        }
        if ($subscription->customerId === null) {
            return null;
        }
        try {
            return $this->customers->getById($subscription->customerId)->getEmail() ?: null;
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function toData(array $row): Subscription
    {
        return new Subscription((int)$row['subscription_id'], (string)$row['type'], (int)$row['reference_id'],
            (int)$row['store_id'], $row['contact_id'] === null ? null : (int)$row['contact_id'],
            $row['customer_id'] === null ? null : (int)$row['customer_id'], (string)$row['permission_ref'],
            (string)$row['captured_at']);
    }
}
