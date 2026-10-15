<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\Data\WithdrawalContext;
use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Model\SubscriptionContactReference;
use CommerceKings\Notification\Model\SubscriptionGuard;
use CommerceKings\Notification\Model\SubscriptionRepository;
use CommerceKings\Notification\Model\WithdrawalHandler;
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
        $consentGuard = $this->createStub(ConsentGuardInterface::class);
        $consentGuard->method('validCondition')->willReturnCallback(fn ($column) => 'VALID(' . $column . ')');
        return new SubscriptionGuard($this->resource(), $consentGuard);
    }

    public function testGuardConditionNeedsTheRowAndAValidConsent(): void
    {
        $this->assertSame('EXISTS (SELECT 1 FROM ck_notification_subscription AS ck_ns WHERE ck_ns.subscription_id = c.subscription_id'
            . ' AND VALID(ck_ns.consent_id))', $this->guard()->validCondition('c.subscription_id'));
    }

    public function testGuardChecksIdTypeAndConsent(): void
    {
        $this->fetchOneResult = '7';
        $this->assertTrue($this->guard()->isValid(7, 'test_type'));
        $this->assertSame([['ck_ns.subscription_id = ?', 7], ['ck_ns.type = ?', 'test_type'], ['VALID(ck_ns.consent_id)', null]],
            $this->selectWhere);
        $this->fetchOneResult = false;
        $this->assertFalse($this->guard()->isValid(7, 'test_type'));
    }

    public function testWithdrawalDeletesTheSubscriptionsOfEveryType(): void
    {
        $handler = new WithdrawalHandler($this->resource());
        $handler->execute(new WithdrawalContext('test_purpose', ['a', 'b'], 1, 'browser', 'banner'));
        $handler->execute(new WithdrawalContext('test_purpose', [], 1, 'browser', 'banner'));
        $this->assertSame([['table' => 'ck_notification_subscription', 'where' => ['consent_id IN (?)' => ['a', 'b']]]],
            $this->deletes);
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
            'consent', 'browser', '2026-01-01 00:00:00');
        $this->assertSame('guest@example.test', $repository->getEmail($subscription(5, null)));
        $this->assertSame('account@example.test', $repository->getEmail($subscription(null, 9)));
        $this->assertNull($repository->getEmail($subscription(null, 10)), 'deleted customer');
        $this->assertNull($repository->getEmail($subscription(null, null)));
    }

    public function testGetMapsTheRow(): void
    {
        $this->fetchRowResult = ['subscription_id' => '7', 'type' => 'test_type', 'reference_id' => '42', 'store_id' => '1',
            'subject_key' => '', 'contact_id' => null, 'customer_id' => '9', 'consent_id' => 'consent',
            'browser_hash' => 'browser', 'captured_at' => '2026-01-01 00:00:00'];
        $repository = new SubscriptionRepository($this->resource(), $this->createStub(ContactRepositoryInterface::class),
            $this->createStub(CustomerRepositoryInterface::class));
        $this->assertEquals(new Subscription(7, 'test_type', 42, 1, null, 9, 'consent', 'browser', '2026-01-01 00:00:00'),
            $repository->get(7));
        $this->fetchRowResult = false;
        $this->assertNull($repository->get(8));
    }
}
