<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\KeyedHash;
use CommerceKings\Notification\Model\StopList;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

/** What a stop removes: the address's stoppable subscriptions, matched exactly like contacts. */
class StopListSubscriptionsTest extends TestCase
{
    private array $deleted = [];

    private function stop(array $customers, array $types): void
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('__toString')->willReturn('SELECT contact_id');
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchCol')->willReturn([]);
        $db->method('fetchPairs')->willReturn($customers);
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) =>
            str_replace('?', implode(',', array_map('strval', (array)$value)), $text));
        $db->method('delete')->willReturnCallback(function ($table, $where) {
            if ($table === 'ck_notification_subscription') {
                $this->deleted[] = $where;
            }
            return 0;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $hashes = $this->createStub(KeyedHash::class);
        $hashes->method('all')->willReturn([str_repeat('h', 64)]);
        (new StopList($resource, $this->createStub(EncryptorInterface::class), $this->createStub(UrlInterface::class),
            $this->createStub(Config::class), new RecipientNormalizerPool(),
            $this->createStub(SubscriptionPermissionInterface::class), $hashes, new SubscriptionTypePool($types),
            new \CommerceKings\Notification\Model\ContactRepository($resource, new RecipientNormalizerPool())))
            ->stop(Channel::EMAIL, 'jose@example.test');
    }

    private function type(bool $stoppable): SubscriptionTypeInterface
    {
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('isStoppable')->willReturn($stoppable);
        return $type;
    }

    public function testCustomersMatchLikeContactsNotByTheCollation(): void
    {
        // the email column's collation also returns other accents; letter case and spaces are the same address
        $this->stop([5 => ' JOSE@Example.test ', 6 => 'josé@example.test'], ['cart_reminder' => $this->type(true)]);
        $this->assertSame(['(contact_id IN (SELECT contact_id) OR (customer_id IN (5) AND contact_id IS NULL))'], $this->deleted[0],
            'the account email\'s subscriptions only: a customer\'s phone contact is another channel');
    }

    public function testWithoutAMatchingCustomerOnlyContactsCount(): void
    {
        $this->stop([6 => 'josé@example.test'], []);
        $this->assertSame(['contact_id IN (SELECT contact_id)'], $this->deleted[0]);
    }

    public function testServiceSubscriptionsStay(): void
    {
        $this->stop([], ['cart_reminder' => $this->type(true), 'order_status' => $this->type(false)]);
        $this->assertSame(['contact_id IN (SELECT contact_id)', 'type NOT IN (?)' => ['order_status']], $this->deleted[0],
            'a stop removes stoppable subscriptions only');
    }
}
