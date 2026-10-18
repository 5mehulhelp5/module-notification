<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Cron\CleanUpSubscriptions;
use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use CommerceKings\Notification\Model\SubscriptionContactReference;
use CommerceKings\Notification\Model\SubscriptionGuard;
use CommerceKings\Notification\Model\SubscriptionRemoval;
use CommerceKings\Notification\Model\SubscriptionRepository;
use CommerceKings\Notification\Test\Unit\DbTestTrait;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

/** Guard, withdrawal, contact reference and repository around ck_notification_subscription. */
class SubscriptionTest extends TestCase
{
    use DbTestTrait;

    private function guard(): SubscriptionGuard
    {
        $permission = $this->createStub(SubscriptionPermissionInterface::class);
        $permission->method('validCondition')->willReturnCallback(fn ($column) => 'VALID(' . $column . ')');
        return new SubscriptionGuard($this->resource(),
            new \CommerceKings\Notification\Model\PermissionResolver($permission, new \CommerceKings\Notification\Model\SubscriptionTypePool([]),
                $this->resource()));
    }

    public function testGuardConditionNeedsTheRowAndAValidConsent(): void
    {
        $this->assertSame('EXISTS (SELECT 1 FROM ck_notification_subscription AS ck_ns WHERE ck_ns.subscription_id = c.subscription_id'
            . ' AND VALID(ck_ns.permission_ref))', $this->guard()->validCondition('c.subscription_id'));
    }

    public function testGuardChecksIdTypeAndConsent(): void
    {
        $this->fetchOneResult = '7';
        $this->assertTrue($this->guard()->isValid(7, 'test_type'));
        $this->assertSame([['ck_ns.subscription_id = ?', 7], ['ck_ns.type = ?', 'test_type'], ['VALID(ck_ns.permission_ref)', null]],
            $this->selectWhere);
        $this->fetchOneResult = false;
        $this->assertFalse($this->guard()->isValid(7, 'test_type'));
    }

    public function testRevokedPermissionsRemoveTheSubscriptionsOfEveryType(): void
    {
        $removal = new SubscriptionRemoval($this->resource());
        $removal->removeByPermission(['a', 'b']);
        $this->assertSame(0, $removal->removeByPermission([]));
        $this->assertSame(0, $removal->removeByPermission(['']), '"no reference" is shared - never a removal key');
        $removal->removeByPermission(['', 'c']);
        $this->assertSame([
            ['table' => 'ck_notification_subscription', 'where' => ['permission_ref IN (?)' => ['a', 'b']]],
            ['table' => 'ck_notification_subscription', 'where' => ['permission_ref IN (?)' => ['c']]],
        ], $this->deletes);
    }

    public function testASingleSubscriptionCanBeRemoved(): void
    {
        $this->deleteResult = 1;
        $this->assertTrue((new SubscriptionRemoval($this->resource()))->remove(7));
        $this->assertSame([['table' => 'ck_notification_subscription', 'where' => ['subscription_id = ?' => 7]]],
            $this->deletes);
        $this->deleteResult = 0;
        $this->assertFalse((new SubscriptionRemoval($this->resource()))->remove(7), 'already gone');
    }

    /** The permission states SubscriptionManagement relies on. */
    public function testPermissionStates(): void
    {
        $granted = Permission::granted('ref');
        $this->assertTrue($granted->isGranted());
        $this->assertSame('ref', $granted->reference);
        $this->assertFalse($granted->removesExisting);
        $this->assertTrue(Permission::granted()->isGranted(), 'a permission without a reference is still granted');
        $this->assertSame('', Permission::granted()->reference);
        $this->assertFalse(Permission::absent()->isGranted());
        $this->assertTrue(Permission::absent()->removesExisting);
        $this->assertFalse(Permission::refused()->isGranted());
        $this->assertFalse(Permission::refused()->removesExisting);
    }

    public function testCleanupRemovesSubscriptionsWhoseReferenceIsGoneForEveryRegisteredType(): void
    {
        $types = [];
        foreach (['cart_reminder' => 'NO_CART', 'back_in_stock' => 'NO_PRODUCT'] as $code => $condition) {
            $types[$code] = $this->createStub(SubscriptionTypeInterface::class);
            $types[$code]->method('orphanCondition')->willReturnCallback(fn ($column) => $condition . '(' . $column . ')');
        }
        $this->queryRowCounts = [2, 1];
        $this->assertSame(3, (new CleanUpSubscriptions($this->resource(), new SubscriptionTypePool($types + ['broken' => new \stdClass()])))
            ->execute());
        $this->assertSame([
            ['DELETE s FROM ck_notification_subscription AS s WHERE s.type = ? AND (NO_CART(s.reference_id))', ['cart_reminder']],
            ['DELETE s FROM ck_notification_subscription AS s WHERE s.type = ? AND (NO_PRODUCT(s.reference_id))', ['back_in_stock']],
        ], $this->queries, 'one statement per type; unregistered types are left alone');
    }

    public function testSubscriptionsKeepTheirContacts(): void
    {
        $this->assertSame('EXISTS (SELECT 1 FROM ck_notification_subscription AS ck_ns_c WHERE ck_ns_c.contact_id = n.contact_id)',
            (new SubscriptionContactReference($this->resource()))->usedCondition('n.contact_id'));
    }

    public function testEmailIsTheContactsOrTheCustomersCurrentOne(): void
    {
        $contacts = $this->createStub(ContactRepositoryInterface::class);
        $contacts->method('getEmail')->willReturn('guest@example.test');
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('account@example.test');
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        $customers->method('getById')->willReturnCallback(fn ($id) => $id === 9 ? $customer
            : throw new NoSuchEntityException(__('gone')));
        $repository = new SubscriptionRepository($this->resource(), $contacts, $customers);
        $subscription = fn (?int $contact, ?int $customer) => new Subscription(1, 'test_type', 42, 1, $contact, $customer,
            'consent', '2026-01-01 00:00:00');
        $this->assertSame('guest@example.test', $repository->getEmail($subscription(5, null)));
        $this->assertSame('account@example.test', $repository->getEmail($subscription(null, 9)));
        $this->assertNull($repository->getEmail($subscription(null, 10)), 'deleted customer');
        $this->assertNull($repository->getEmail($subscription(null, null)));
    }

    public function testRecipientIsTheContactsChannelAndAddressOrTheCustomersEmail(): void
    {
        $contacts = $this->createStub(ContactRepositoryInterface::class);
        $contacts->method('getRecipient')->willReturn(['channel' => 'sms', 'recipient' => '+381601234567']);
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('account@example.test');
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        $customers->method('getById')->willReturn($customer);
        $repository = new SubscriptionRepository($this->resource(), $contacts, $customers);
        $subscription = fn (?int $contact, ?int $customer) => new Subscription(1, 'test_type', 42, 1, $contact, $customer,
            'consent', '2026-01-01 00:00:00');
        $this->assertSame(['channel' => 'sms', 'recipient' => '+381601234567'], $repository->getRecipient($subscription(5, null)));
        $this->assertSame(['channel' => 'email', 'recipient' => 'account@example.test'],
            $repository->getRecipient($subscription(null, 9)));
        $this->assertNull($repository->getRecipient($subscription(null, null)));
    }

    public function testGetMapsTheRow(): void
    {
        $this->fetchRowResult = ['subscription_id' => '7', 'type' => 'test_type', 'reference_id' => '42', 'store_id' => '1',
            'subject_key' => '', 'contact_id' => null, 'customer_id' => '9', 'permission_ref' => 'consent',
            'captured_at' => '2026-01-01 00:00:00'];
        $repository = new SubscriptionRepository($this->resource(), $this->createStub(ContactRepositoryInterface::class),
            $this->createStub(CustomerRepositoryInterface::class));
        $this->assertEquals(new Subscription(7, 'test_type', 42, 1, null, 9, 'consent', '2026-01-01 00:00:00'),
            $repository->get(7));
        $this->fetchRowResult = false;
        $this->assertNull($repository->get(8));
    }
}
