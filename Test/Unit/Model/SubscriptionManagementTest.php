<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use CommerceKings\Notification\Test\Unit\DbTestTrait;
use Laminas\Filter\StringTrim;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\StringLength;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use DbTestTrait;

    private ?Target $target;
    private bool $single = true;
    /** the permission's answer */
    private Permission $current;
    private array $authorized = [];
    /** purposes the permission was asked about */
    private array $consentReads = [];
    private array $contacts = [];
    private ?\Throwable $contactFailure = null;

    protected function setUp(): void
    {
        $this->target = new Target(42, 1, true, 'STILL_YOURS');
        $this->current = Permission::granted('new-consent');
    }

    private function management(): SubscriptionManagement
    {
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('getPurpose')->willReturn('test_purpose');
        $type->method('isSingle')->willReturnCallback(fn () => $this->single);
        $type->method('authorize')->willReturnCallback(function ($reference, $customerId) {
            $this->authorized[] = [$reference, $customerId];
            return $this->target;
        });
        $permission = $this->createStub(SubscriptionPermissionInterface::class);
        $permission->method('check')->willReturnCallback(function (SubscriptionTypeInterface $type) {
            $this->consentReads[] = $type->getPurpose();
            return $this->current;
        });
        $permission->method('validCondition')->willReturnCallback(fn ($column) => 'VALID(' . $column . ')');
        $contacts = $this->createStub(ContactRepositoryInterface::class);
        $contacts->method('save')->willReturnCallback(function ($website, $email) {
            $this->contacts[] = [$website, $email];
            if ($this->contactFailure !== null) {
                throw $this->contactFailure;
            }
            return 5;
        });
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $filters = $this->createStub(FilterManager::class);
        $filters->method('get')->willReturn(new StringTrim());
        return new SubscriptionManagement($this->resource(), new SubscriptionTypePool(['test_type' => $type]),
            $permission, $contacts, $stores,
            new EmailAddress(), new StringLength(['max' => 255]), $filters);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->deletes);
        $this->assertSame([], $this->contacts);
    }

    public function testContactPersistenceFailureIsNotAcknowledgedAndCanBeRetried(): void
    {
        $this->contactFailure = new \RuntimeException('Contact storage unavailable');
        $management = $this->management();
        try {
            $management->subscribeGuest('test_type', 'masked', 'guest@example.test');
            $this->fail('An unstored contact must not be acknowledged.');
        } catch (\RuntimeException $error) {
            $this->assertSame($this->contactFailure, $error);
        }
        $this->assertSame([], $this->queries, 'no subscription can be written without a contact');
        $this->assertSame([], $this->deletes, 'a failed save must not unsubscribe the previous recipient');
        $this->contactFailure = null;
        $this->assertTrue($management->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertCount(1, $this->queries);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('subscriberKinds')]
    public function testSubscriptionPersistenceFailureIsNotAcknowledgedAndCanBeRetried(bool $guest): void
    {
        $this->queryFailure = new \RuntimeException('Subscription storage unavailable');
        $management = $this->management();
        $subscribe = fn () => $guest
            ? $management->subscribeGuest('test_type', 'masked', 'guest@example.test')
            : $management->subscribeCustomer(9, 'test_type', '42');
        try {
            $subscribe();
            $this->fail('An unstored subscription must not be acknowledged.');
        } catch (\RuntimeException $error) {
            $this->assertSame($this->queryFailure, $error);
        }
        $this->assertCount($guest ? 1 : 0, $this->contacts);
        $this->assertCount(1, $this->queries);
        $this->assertStringContainsString('INSERT INTO ck_notification_subscription', $this->queries[0][0]);
        $this->assertSame([], $this->deletes);
        $this->assertSame([], $this->updates);
        $this->queryFailure = null;
        $this->assertTrue($subscribe());
        $this->assertCount(2, $this->queries);
        $this->assertSame($this->queries[0], $this->queries[1], 'retry preserves the authorized scope and guards');
    }

    public static function subscriberKinds(): array
    {
        return ['guest' => [true], 'customer' => [false]];
    }

    public function testGuestSubscribesWithOneGuardedInsertAfterAllChecks(): void
    {
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', '  guest@example.test '));
        $this->assertSame([['masked', 0]], $this->authorized);
        $this->assertSame(['test_purpose'], $this->consentReads);
        $this->assertSame([[1, 'guest@example.test']], $this->contacts);
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('INSERT INTO ck_notification_subscription', $sql);
        $this->assertStringContainsString("FROM DUAL WHERE VALID('new-consent') AND (STILL_YOURS) ON DUPLICATE KEY UPDATE", $sql);
        $this->assertSame(['test_type', 42, 1, '', 5, null, 'new-consent'], array_slice($bind, 0, 7));
        $this->assertSame([5, null, 'new-consent'], array_slice($bind, 7, 3), 'compared before replacing');
    }

    public function testOnePerPersonTypesKeyByContactOrCustomer(): void
    {
        $this->single = false;
        $this->management()->subscribeGuest('test_type', '42', 'guest@example.test');
        $this->management()->subscribeCustomer(9, 'test_type', '42');
        $this->assertSame('c:5', $this->queries[0][1][3]);
        $this->assertSame('u:9', $this->queries[1][1][3]);
        $this->assertSame([null, 9], array_slice($this->queries[1][1], 4, 2), 'customers have no contact');
        $this->assertSame([['42', 0], ['42', 9]], $this->authorized);
    }

    public function testIdenticalRetryIsAcknowledgedAndARefusalIsNot(): void
    {
        $this->queryRowCounts = [0, 0];
        $this->fetchOneResult = '3';
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertContains(["VALID('new-consent')", null], $this->selectWhere);
        $this->assertContains(['(STILL_YOURS)', null], $this->selectWhere);
        $this->assertContains(['contact_id = \'5\'', null], $this->selectWhere);
        $this->fetchOneResult = false;
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
    }

    /** @dataProvider invalidEmails */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEmails')]
    public function testInvalidEmailIsRejectedBeforeAuthorization(mixed $email): void
    {
        try {
            $this->management()->subscribeGuest('test_type', 'masked', $email);
            $this->fail('Invalid input was accepted.');
        } catch (InputException) {
            $this->assertSame([], $this->authorized);
            $this->assertNothingWritten();
        }
    }

    public static function invalidEmails(): array
    {
        return [['not-an-email'], ['a@'], [str_repeat('a', 250) . '@test.com'], [null], [false], [true], [123],
            [['a@test.com']]];
    }

    public function testErrorsDoNotEchoTheEmail(): void
    {
        $email = str_repeat('a', 250) . '@example.test';
        try {
            $this->management()->subscribeGuest('test_type', 'masked', $email);
            $this->fail('Invalid input was accepted.');
        } catch (InputException $exception) {
            $this->assertCount(2, $exception->getErrors());
            foreach ($exception->getErrors() as $error) {
                $this->assertStringNotContainsString($email, $error->getMessage());
            }
        }
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->expectException(InputException::class);
        $this->management()->subscribeCustomer(9, 'removed_type', '42');
    }

    public function testRefusedAuthorizationReadsNoConsentAndChangesNothing(): void
    {
        $this->target = null;
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'foreign', 'guest@example.test'));
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'foreign', ''));
        $this->assertFalse($this->management()->subscribeCustomer(9, 'test_type', 'foreign'));
        $this->assertSame([], $this->consentReads);
        $this->assertNothingWritten();
    }

    public function testGuestWithoutEmailConsentOrAcceptingTypeIsUnsubscribed(): void
    {
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'masked', '  '));
        $this->assertSame([], $this->consentReads, 'unsubscribing records no consent');
        $this->current = Permission::absent();
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->target = new Target(42, 1, false, 'STILL_YOURS');
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertCount(3, $this->deletes);
        $this->assertSame(['type = ?', 'reference_id = ?', "subject_key = ''", 'customer_id IS NULL', '(STILL_YOURS)'],
            self::conditions($this->deletes[0]['where']));
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->contacts);
    }

    public function testGuestCannotRemoveAPerPersonSubscriptionByEmptyEmail(): void
    {
        $this->single = false;
        $this->assertFalse($this->management()->subscribeGuest('test_type', '42', ''));
        $this->assertNothingWritten();
    }

    public function testCustomerWithoutConsentHereOrAcceptingTypeChangesNothing(): void
    {
        $this->current = Permission::absent();
        $this->assertFalse($this->management()->subscribeCustomer(9, 'test_type', '42'));
        $this->target = new Target(42, 1, false);
        $this->assertFalse($this->management()->subscribeCustomer(9, 'test_type', '42'));
        $this->assertFalse($this->management()->subscribeCustomer(0, 'test_type', '42'));
        $this->assertSame(['test_purpose'], $this->consentReads);
        $this->assertNothingWritten();
    }

    /** e.g. a consent of another website or purpose (consent bridge) */
    public function testARefusedPermissionChangesNothing(): void
    {
        $this->current = Permission::refused();
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertFalse($this->management()->subscribeCustomer(9, 'test_type', '42'));
        $this->assertNothingWritten();
    }

    /** Notification alone: every authorized subscription is allowed, with no reference and no SQL requirement. */
    public function testOpenPermissionSubscribesWithoutAReference(): void
    {
        $open = new \CommerceKings\Notification\Model\OpenPermission();
        $this->assertSame('', $open->check($this->createStub(SubscriptionTypeInterface::class), $this->target)->reference);
        $this->assertSame('1 = 1', $open->validCondition('x'));
        $this->assertTrue($open->isValidFor('', 'test_purpose', 1));
    }

    /** ...and its subscriber cannot be recognized and has no unsubscribe link. */
    public function testAnonymousSubscriberIsNeitherRecognizedNorLinked(): void
    {
        $anonymous = new \CommerceKings\Notification\Model\AnonymousSubscriber();
        $this->assertFalse($anonymous->identifiesVisitor());
        $this->assertFalse($anonymous->isCurrentVisitor('any'));
        $this->assertNull($anonymous->getUnsubscribeUrl(new \CommerceKings\Notification\Api\Data\Subscription(7, 'test_type',
            42, 1, 5, null, '', '2026-01-01 00:00:00')));
    }
}
