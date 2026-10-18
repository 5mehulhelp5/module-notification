<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Api\SubscriptionManagementInterface;
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
    private ?\CommerceKings\Notification\Api\StopListInterface $stopList = null;

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
    private string $channel = 'email';
    private array $found = [];
    /** the type's own permission (getPermission()); null = the shop-wide one */
    private ?SubscriptionPermissionInterface $ownPermission = null;
    /** dispatched EVENT_SAVED data */
    private array $events = [];
    /** the recipient each permission check() was given */
    private array $checkedRecipients = [];
    private ?\Throwable $observerFailure = null;
    private array $errors = [];

    protected function setUp(): void
    {
        $this->target = new Target(42, 1, true, 'STILL_YOURS');
        $this->current = Permission::granted('new-consent');
    }

    private function management(): SubscriptionManagement
    {
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('getPurpose')->willReturn('test_purpose');
        $type->method('getChannel')->willReturnCallback(fn () => $this->channel);
        $type->method('isSingle')->willReturnCallback(fn () => $this->single);
        $type->method('getPermission')->willReturnCallback(fn () => $this->ownPermission);
        $type->method('authorize')->willReturnCallback(function ($reference, $customerId) {
            $this->authorized[] = [$reference, $customerId];
            return $this->target;
        });
        $permission = $this->createStub(SubscriptionPermissionInterface::class);
        $permission->method('check')->willReturnCallback(function (SubscriptionTypeInterface $type, $target, $recipient = null) {
            $this->consentReads[] = $type->getPurpose();
            $this->checkedRecipients[] = $recipient;
            return $this->current;
        });
        $permission->method('validCondition')->willReturnCallback(fn ($column) => 'VALID(' . $column . ')');
        $contacts = $this->createStub(ContactRepositoryInterface::class);
        $contacts->method('find')->willReturnCallback(function ($website, $recipient, $channel) {
            $this->found[] = [$website, $recipient, $channel];
            return null;
        });
        $contacts->method('save')->willReturnCallback(function ($website, $email, $channel = 'email') {
            $this->contacts[] = $channel === 'email' ? [$website, $email] : [$website, $email, $channel];
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
        $types = new SubscriptionTypePool(['test_type' => $type]);
        return new SubscriptionManagement($this->resource(), $types,
            new \CommerceKings\Notification\Model\PermissionResolver($permission, $types, $this->resource()), $contacts, $stores,
            new EmailAddress(), new StringLength(['max' => 255]), $filters, $this->stopList ?? $this->createStub(\CommerceKings\Notification\Api\StopListInterface::class),
            $this->customerRepository(), new \CommerceKings\Notification\Model\RecipientNormalizerPool(['sms' => new class
                implements \CommerceKings\Notification\Api\RecipientNormalizerInterface {
                public function normalize(string $recipient): string
                {
                    $digits = preg_replace('/\D/', '', $recipient);
                    return strlen($digits) >= 8 ? '+' . $digits : '';
                }
            }]), $this->eventManager(), $this->logger());
    }

    private function eventManager(): \Magento\Framework\Event\ManagerInterface
    {
        $events = $this->createStub(\Magento\Framework\Event\ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function ($name, $data) {
            $this->transactionEvents[] = 'event';
            $this->events[] = [$name, $data];
            if ($this->observerFailure !== null) {
                throw $this->observerFailure;
            }
        });
        return $events;
    }

    private function logger(): \Psr\Log\LoggerInterface
    {
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message, $context) { $this->errors[] = [$message, $context]; });
        return $logger;
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
        $this->assertSame(['beginTransaction', 'rollBack'], $this->transactionEvents);
        $this->contactFailure = null;
        $this->assertTrue($management->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertCount(1, $this->queries);
        $this->assertSame(['beginTransaction', 'rollBack', 'beginTransaction', 'commit', 'event'], $this->transactionEvents,
            'only the stored retry is announced');
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
        $this->assertSame($guest ? ['beginTransaction', 'rollBack'] : [], $this->transactionEvents);
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
        $this->assertSame(['beginTransaction', 'commit', 'event'], $this->transactionEvents, 'announced after the commit');
    }

    /** A type with its own permission (e.g. an SMS checkbox) is checked and guarded by it, not the shop-wide one. */
    public function testATypeWithItsOwnPermissionIsCheckedAndGuardedByIt(): void
    {
        $this->current = Permission::refused();
        $this->ownPermission = $this->createStub(SubscriptionPermissionInterface::class);
        $this->ownPermission->method('check')->willReturn(Permission::granted('sms-checkbox'));
        $this->ownPermission->method('validCondition')->willReturnCallback(fn ($column) => 'OWN(' . $column . ')');
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertSame([], $this->consentReads, 'the shop-wide permission is not asked');
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString("WHERE OWN('sms-checkbox') AND (STILL_YOURS)", $sql);
        $this->assertSame('sms-checkbox', $bind[6]);
    }

    public function testStoppedRecipientIsAnsweredLikeAStoredSubscriptionButNothingIsWritten(): void
    {
        $touched = [];
        $this->stopList = $this->createStub(\CommerceKings\Notification\Api\StopListInterface::class);
        $this->stopList->method('touch')->willReturnCallback(function ($channel, $recipient) use (&$touched) {
            $touched[] = [$channel, $recipient];
            return true;
        });
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'),
            'the same answer as a stored subscription: the list cannot be probed');
        $this->assertTrue($this->management()->subscribeCustomer(9, 'test_type', 'masked'));
        $this->assertSame([], $this->queries, 'no subscription written');
        $this->assertSame([], $this->contacts, 'no contact (no address) stored');
        $this->assertCount(1, $this->deletes, 'the guest\'s previous subscription of this cart is removed');
        $this->assertSame('ck_notification_subscription', $this->deletes[0]['table']);
        $this->assertContains('customer_id IS NULL', self::conditions($this->deletes[0]['where']), 'only the guest row');
        $this->assertSame([[1, 'guest@example.test', 'email']], $this->found,
            'the contact read a stored sign-up does too, so the answer is not measurably faster');
        $this->assertSame([['email', 'guest@example.test'], ['email', 'customer@test.com']], $touched,
            'each attempt restarts the lift timer');
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
        $this->assertSame(['beginTransaction', 'commit', 'beginTransaction', 'commit'], $this->transactionEvents);
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

    /** Notification alone: every authorized subscription is allowed, with no reference. */
    public function testOpenPermissionSubscribesWithoutAReference(): void
    {
        $open = new \CommerceKings\Notification\Model\OpenPermission();
        $this->assertSame('', $open->check($this->createStub(SubscriptionTypeInterface::class), $this->target)->reference);
        $this->assertSame("s.permission_ref = ''", $open->validCondition('s.permission_ref'));
        $this->assertTrue($open->isValidFor('', 'test_purpose', 1));
    }

    /** After switching from opt-in to opt-out, a consent-based subscription does not come back to life. */
    public function testOpenPermissionDoesNotReviveSubscriptionsFromAPermissionModule(): void
    {
        $open = new \CommerceKings\Notification\Model\OpenPermission();
        $this->assertFalse($open->isValidFor('a3f0c2d1e4b5a6c7d8e9f0a1b2c3d4e5', 'test_purpose', 1));
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

    private function customerRepository(): \Magento\Customer\Api\CustomerRepositoryInterface
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getEmail')->willReturn('customer@test.com');
        $customers = $this->createStub(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customers->method('getById')->willReturn($customer);
        return $customers;
    }

    /** A type on another channel: its own contact, its own stop list. */
    public function testAnSmsTypeSubscribesAPhoneNumber(): void
    {
        $this->channel = 'sms';
        $touched = [];
        $this->stopList = $this->createStub(\CommerceKings\Notification\Api\StopListInterface::class);
        $this->stopList->method('touch')->willReturnCallback(function ($channel, $recipient) use (&$touched) {
            $touched[] = [$channel, $recipient];
            return false;
        });
        $this->fetchOneResult = '7';
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', recipient: ' +381 60 123 4567 '));
        $this->assertSame([[1, '+381 60 123 4567', 'sms']], $this->contacts, 'a phone contact on the sms channel');
        $this->assertSame([['sms', '+381 60 123 4567']], $touched, 'checked against the sms stop list');
    }

    public function testAnSmsTypeRefusesEmailsAndInvalidNumbersWithoutWriting(): void
    {
        $this->channel = 'sms';
        foreach (['guest@example.test', 'abc', ['+381601234567']] as $recipient) {
            try {
                $this->management()->subscribeGuest('test_type', 'masked', $recipient);
                $this->fail('Expected Magento\'s 400');
            } catch (\Magento\Framework\Exception\InputException $error) {
                $this->assertStringNotContainsString('example.test', $error->getMessage(), 'never echoes the input');
            }
        }
        $this->assertSame([], $this->contacts);
        $this->assertSame([], $this->queries);
    }

    public function testAChannelWithoutANormalizerTakesNoSignUps(): void
    {
        $this->channel = 'carrier_pigeon';
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->management()->subscribeGuest('test_type', 'masked', recipient: 'loft 7');
    }

    public function testAnEmptyRecipientUnsubscribesOnAnyChannel(): void
    {
        $this->channel = 'sms';
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'masked', recipient: '  '));
        $this->assertCount(1, $this->deletes);
        $this->assertSame([], $this->contacts);
    }

    /** A new row: EVENT_SAVED after the commit, with everything an observer (e.g. an SMS opt-in message) needs. */
    public function testANewSubscriptionIsAnnouncedAfterTheCommit(): void
    {
        $this->fetchOneResult = '31';   // SELECT LAST_INSERT_ID()
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertSame([[SubscriptionManagementInterface::EVENT_SAVED, ['subscription_id' => 31, 'type' => 'test_type',
            'channel' => 'email', 'store_id' => 1, 'contact_id' => 5, 'customer_id' => null, 'created' => true,
            'previous_contact_id' => null, 'first_for_address' => false]]], $this->events,
            'the stub answers every read with 31, so the address counts as seen (real reads: MultiChannelTest)');
        $this->assertStringContainsString('subscription_id = LAST_INSERT_ID(subscription_id)', $this->queries[0][0],
            'an update reports the existing row\'s id');
    }

    public function testAChangedSubscriptionIsAnnouncedAsNotCreated(): void
    {
        $this->queryRowCounts = [2];   // MySQL: an existing row changed (e.g. a new address or permission)
        $this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test');
        $this->assertFalse($this->events[0][1]['created']);
    }

    /** previous_contact_id tells a new address (e.g. confirm the new number) from a renewal of the same one. */
    public function testAChangedRowReportsItsPreviousContact(): void
    {
        $this->queryRowCounts = [2];
        $this->fetchOneResult = '4';   // the row's contact before the write, then LAST_INSERT_ID()
        $this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test');
        $this->assertSame([4, 5], [$this->events[0][1]['previous_contact_id'], $this->events[0][1]['contact_id']]);
    }

    /** Nothing written, nothing announced: an identical retry, a refusal, a stopped recipient. */
    public function testOnlyAWrittenRowIsAnnounced(): void
    {
        $this->queryRowCounts = [0];
        $this->fetchOneResult = '7';
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'), 'identical retry');
        $this->current = Permission::refused();
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->current = Permission::granted('new-consent');
        $this->stopList = $this->createStub(\CommerceKings\Notification\Api\StopListInterface::class);
        $this->stopList->method('touch')->willReturn(true);
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertSame([], $this->events);
    }

    public function testAnObserverErrorIsLoggedAndNeverFailsTheSignUp(): void
    {
        $this->observerFailure = new \RuntimeException('observer broke with guest@example.test');
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', 'guest@example.test'));
        $this->assertSame(['exception_class' => \RuntimeException::class], $this->errors[0][1], 'the class only, no message');
    }

    /** A customer on another channel gives the number; it is stored as their contact, next to the customer id. */
    public function testACustomerSubscribesAPhoneNumberAsTheirContact(): void
    {
        $this->channel = 'sms';
        $this->single = false;
        $this->assertTrue($this->management()->subscribeCustomer(17, 'test_type', '42', ' +381 60 123 4567 '));
        $this->assertSame([[1, '+381 60 123 4567', 'sms']], $this->contacts);
        [$sql, $bind] = $this->queries[0];
        $this->assertSame(['test_type', 42, 1, 'u:17', 5, 17], array_slice($bind, 0, 6),
            'contact and customer; one per customer whatever the number');
        $this->assertSame(['beginTransaction', 'commit', 'event'], $this->transactionEvents);
        $this->assertSame([5, 17, 'sms'], [$this->events[0][1]['contact_id'], $this->events[0][1]['customer_id'],
            $this->events[0][1]['channel']]);
    }

    public function testACustomerOnAnotherChannelNeedsAValidRecipient(): void
    {
        $this->channel = 'sms';
        foreach ([null, '', '  ', 'abc', ['+381601234567']] as $recipient) {
            try {
                $this->management()->subscribeCustomer(17, 'test_type', '42', $recipient);
                $this->fail('Expected Magento\'s 400');
            } catch (\Magento\Framework\Exception\InputException) {
            }
        }
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->authorized, 'validated before the type is asked');
    }

    public function testAnEmailTypeKeepsTheAccountEmailAndTakesNoRecipient(): void
    {
        $this->assertTrue($this->management()->subscribeCustomer(17, 'test_type', '42'));
        $this->assertSame([], $this->contacts, 'no contact: the account email');
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->management()->subscribeCustomer(17, 'test_type', '42', 'other@example.test');
    }

    /** A stopped number is answered like a stored sign-up for customers too, and restarts the lift timer. */
    public function testAStoppedNumberIsNotStoredForACustomer(): void
    {
        $this->channel = 'sms';
        $touched = [];
        $this->stopList = $this->createStub(\CommerceKings\Notification\Api\StopListInterface::class);
        $this->stopList->method('touch')->willReturnCallback(function ($channel, $recipient) use (&$touched) {
            $touched[] = [$channel, $recipient];
            return true;
        });
        $this->assertTrue($this->management()->subscribeCustomer(17, 'test_type', '42', '+381601234567'));
        $this->assertSame([['sms', '+381601234567']], $touched);
        $this->assertSame([], $this->contacts);
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->events);
    }

    /** The permission sees the address the subscription is stored with, in the sender's form. */
    public function testThePermissionIsGivenTheAddressTheSubscriptionIsStoredWith(): void
    {
        $this->management()->subscribeGuest('test_type', 'masked', '  guest@example.test ');
        $this->management()->subscribeCustomer(17, 'test_type', '42');
        $this->channel = 'sms';
        $this->management()->subscribeGuest('test_type', 'masked', ' +381 60 123 4567 ');
        $this->management()->subscribeCustomer(17, 'test_type', '42', '+381 60 123 4567');
        $this->assertSame(['guest@example.test', 'customer@test.com', '+381601234567', '+381601234567'],
            $this->checkedRecipients, 'guest address, account email, then the normalized numbers');
    }

    /** A checkbox consent recorded for one number grants only that number, also for the same cart. */
    public function testAnOwnConsentForOneNumberRefusesAnotherNumber(): void
    {
        $this->channel = 'sms';
        $this->current = Permission::refused();   // the shop-wide permission must not decide
        $this->ownPermission = $this->createStub(SubscriptionPermissionInterface::class);
        $this->ownPermission->method('check')->willReturnCallback(fn ($type, $target, $recipient = null)
            => $recipient === '+381601234567' ? Permission::granted('sms-consent-1') : Permission::refused());
        $this->ownPermission->method('validCondition')->willReturnCallback(fn ($column) => 'OWN(' . $column . ')');
        $this->assertTrue($this->management()->subscribeGuest('test_type', 'masked', '+381 60 123 4567 '));
        $this->assertCount(1, $this->queries);
        $this->assertFalse($this->management()->subscribeGuest('test_type', 'masked', '+381 60 999 9999'),
            'another number for the same cart: a fresh check, refused');
        $this->assertCount(1, $this->queries, 'nothing written for it');
        $this->assertFalse($this->management()->subscribeCustomer(17, 'test_type', '42', '+381609999999'));
        $this->assertCount(1, $this->queries);
    }
}
