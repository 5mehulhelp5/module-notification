<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\DeliveryState;
use CommerceKings\Notification\Test\Unit\DbTestTrait;
use PHPUnit\Framework\TestCase;

class DeliveryStateTest extends TestCase
{
    use DbTestTrait;

    public function testJoinsBySubscriptionKeyWithTheGivenAliases(): void
    {
        $this->assertSame('d.type = s.type AND d.reference_id = s.reference_id AND d.subject_key = s.subject_key',
            (new DeliveryState($this->resource()))->joinCondition('d', 's'));
    }

    public function testKeyConditionBindsEveryValue(): void
    {
        $state = new DeliveryState($this->resource());
        $this->assertSame(['type = ?' => 'cart_reminder', 'reference_id = ?' => 42, 'subject_key = ?' => ''],
            $state->keyCondition('cart_reminder', 42));
        $this->assertSame(['c.type = ?' => 'back_in_stock', 'c.reference_id = ?' => 7, 'c.subject_key = ?' => 'u:5'],
            $state->keyCondition('back_in_stock', 7, 'u:5', 'c'));
    }

    /** @return array<string, array{string}> */
    public static function badAliases(): array
    {
        return ['empty' => [''], 'injection' => ['d; DROP TABLE x'], 'dotted' => ['a.b'], 'digit first' => ['1d']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAliases')]
    public function testAliasesMustBeIdentifiers(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeliveryState($this->resource()))->joinCondition($alias, 's');
    }

    public function testEnsureInsertsTheKeyOnceWithBoundValues(): void
    {
        (new DeliveryState($this->resource()))->ensure('back_in_stock', 7, 'c:3');
        $this->assertSame([['INSERT IGNORE INTO ck_notification_delivery_state (type, reference_id, subject_key)'
            . ' VALUES (?, ?, ?)', ['back_in_stock', 7, 'c:3']]], $this->queries);
    }

    public function testLoadReadsOneKey(): void
    {
        $this->fetchRowResult = ['type' => 'cart_reminder', 'reference_id' => '42', 'sent_at' => null];
        $state = new DeliveryState($this->resource());
        $this->assertSame($this->fetchRowResult, $state->load('cart_reminder', 42));
        $this->assertSame([['type = ?', 'cart_reminder'], ['reference_id = ?', 42], ['subject_key = ?', '']],
            $this->selectWhere);
        $this->fetchRowResult = false;
        $this->assertNull($state->load('cart_reminder', 43), 'never queued');
    }
}
