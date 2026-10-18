<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Cron;

use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Cron\CleanUpDeliveryState;
use CommerceKings\Notification\Model\DeliveryState;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use CommerceKings\Notification\Test\Unit\DbTestTrait;
use PHPUnit\Framework\TestCase;

class CleanUpDeliveryStateTest extends TestCase
{
    use DbTestTrait;

    public function testRemovesOrphansAndAPersonsOldStateWithoutASubscription(): void
    {
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('orphanCondition')->willReturnCallback(fn ($column) => 'NOT EXISTS (SELECT 1 FROM quote AS q'
            . ' WHERE q.entity_id = ' . $column . ')');
        $resource = $this->resource();
        $this->queryRowCounts = [2, 1];
        $removed = (new CleanUpDeliveryState($resource, new SubscriptionTypePool(['cart_reminder' => $type]),
            new DeliveryState($resource)))->execute();
        $this->assertSame(3, $removed);
        $this->assertSame([
            ['DELETE d FROM ck_notification_delivery_state AS d WHERE d.type = ? AND (NOT EXISTS (SELECT 1 FROM quote'
                . ' AS q WHERE q.entity_id = d.reference_id))', ['cart_reminder']],
            ["DELETE d FROM ck_notification_delivery_state AS d WHERE d.type = ? AND d.subject_key <> ''"
                . ' AND d.updated_at < NOW() - INTERVAL 90 DAY AND NOT EXISTS (SELECT 1 FROM'
                . ' ck_notification_subscription AS s WHERE d.type = s.type AND d.reference_id = s.reference_id'
                . ' AND d.subject_key = s.subject_key)', ['cart_reminder']],
        ], $this->queries, 'a single type (subject_key \'\') keeps its state while the reference exists');
    }

    public function testNoRegisteredTypesNoStatements(): void
    {
        $resource = $this->resource();
        $this->assertSame(0, (new CleanUpDeliveryState($resource, new SubscriptionTypePool([]),
            new DeliveryState($resource)))->execute());
        $this->assertSame([], $this->queries);
    }
}
