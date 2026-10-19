<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\AddressConsentManagementInterface;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionRemovalInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Model\AddressConsent;
use CommerceKings\Notification\Model\AddressConsentManagement;
use CommerceKings\Notification\Model\AddressConsentRepository;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\KeyedHash;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Laminas\Filter\StringTrim;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Validator\EmailAddress;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** A checkbox consent for one address: recorded for that number only, and only that number is granted. */
class AddressConsentTest extends TestCase
{
    private const WORDING = 'Yes, text me offers at this number.';

    /** repository calls: [method, ...arguments] */
    private array $calls = [];
    private ?string $active = 'ac:found';
    private ?Target $target;
    private array $removed = [];
    private string $wording = self::WORDING;

    protected function setUp(): void
    {
        $this->target = new Target(42, 3, true, '');
    }

    private function stores(): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(2);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return $stores;
    }

    private function repository(): AddressConsentRepository
    {
        $repository = $this->createStub(AddressConsentRepository::class);
        $repository->method('findActive')->willReturnCallback(function (...$arguments) {
            $this->calls[] = ['findActive', ...$arguments];
            return $arguments[2] === '+381601234567' ? $this->active : null;
        });
        $repository->method('record')->willReturnCallback(function (...$arguments) {
            $this->calls[] = ['record', ...$arguments];
            return 'ac:new';
        });
        $repository->method('withdraw')->willReturnCallback(function (...$arguments) {
            $this->calls[] = ['withdraw', ...$arguments];
            return ['ac:old'];
        });
        $repository->method('activeCondition')->willReturnCallback(fn ($column) => 'ACTIVE(' . $column . ')');
        $repository->method('isActive')->willReturnCallback(fn ($reference, $website) => $reference === 'ac:found' && $website === 2);
        return $repository;
    }

    private function permission(): AddressConsent
    {
        return new AddressConsent($this->repository(), $this->stores(), 'sms_offer');
    }

    private function type(?object $permission = null, string $channel = 'sms'): SubscriptionTypeInterface
    {
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('getChannel')->willReturn($channel);
        $type->method('getPermission')->willReturn($permission);
        $type->method('authorize')->willReturnCallback(function ($reference, $customerId) {
            $this->calls[] = ['authorize', $reference, $customerId];
            return $this->target;
        });
        return $type;
    }

    private function management(?SubscriptionTypeInterface $type = null): AddressConsentManagementInterface
    {
        $config = $this->createStub(Config::class);
        $config->method('getAddressConsentWording')->willReturnCallback(fn () => $this->wording);
        $filters = $this->createStub(FilterManager::class);
        $filters->method('get')->willReturn(new StringTrim());
        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('203.0.113.7');
        $hash = $this->createStub(KeyedHash::class);
        $hash->method('current')->willReturnCallback(fn ($data) => 'H(' . $data . ')');
        $removal = $this->createStub(SubscriptionRemovalInterface::class);
        $removal->method('removeByPermission')->willReturnCallback(function ($references) {
            $this->removed[] = $references;
            return 1;
        });
        $digits = new class implements \CommerceKings\Notification\Api\RecipientNormalizerInterface {
            public function normalize(string $recipient): string
            {
                $value = (string)preg_replace('/\D/', '', $recipient);
                return strlen($value) >= 8 ? '+' . $value : '';
            }
        };
        return new AddressConsentManagement(
            new SubscriptionTypePool(['sms_offer' => $type ?? $this->type($this->permission()), 'plain' => $this->type()]),
            $this->repository(), $config, new RecipientNormalizerPool(['sms' => $digits]), $filters, new EmailAddress(),
            $this->stores(), $remote, $hash, $removal
        );
    }

    private function calls(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call[0] === $method));
    }

    public function testOnlyTheNumberWithAConsentIsGranted(): void
    {
        $permission = $this->permission();
        $this->assertSame('ac:found', $permission->check($this->type(), $this->target, '+381601234567')->reference);
        $this->assertSame(['findActive', 'sms_offer', 'sms', '+381601234567', 2], $this->calls[0],
            'this type, its channel, the store\'s website');
        $other = $permission->check($this->type(), $this->target, '+381609999999');
        $this->assertTrue($other->removesExisting, 'another number: absent, the cart\'s previous sign-up goes');
        $this->assertTrue($permission->check($this->type(), $this->target)->removesExisting, 'no address: absent');
    }

    public function testTheStoredReferenceStaysValidOnlyWhileTheConsentIsActive(): void
    {
        $permission = $this->permission();
        $this->assertSame('ACTIVE(s.permission_ref)', $permission->validCondition('s.permission_ref'));
        $this->assertTrue($permission->isValidFor('ac:found', 'any', 3));
        $this->assertFalse($permission->isValidFor('ac:other', 'any', 3));
        $this->assertFalse($permission->isOptOut());
    }

    public function testRecordingStoresTheConfiguredWordingForTheNormalizedNumber(): void
    {
        $this->assertTrue($this->management()->recordGuest('sms_offer', 'masked', ' 381 60 123 4567 ', hash('sha256', self::WORDING)));
        $this->assertSame(['authorize', 'masked', 0], $this->calls('authorize')[0]);
        $this->assertSame(['record', 'sms_offer', 'sms', '+381601234567', 2, 3, self::WORDING,
            'H(commercekings_notification_address_consent_ip|203.0.113.7)'], $this->calls('record')[0]);
    }

    public function testAChangedWordingIsRefusedAndNothingIsRecorded(): void
    {
        try {
            $this->management()->recordGuest('sms_offer', 'masked', '+381601234567', hash('sha256', 'an older text'));
            $this->fail('Expected Magento\'s 400');
        } catch (InputException $error) {
            $this->assertStringContainsString('changed', $error->getMessage());
        }
        $this->wording = '';
        try {
            $this->management()->recordGuest('sms_offer', 'masked', '+381601234567', hash('sha256', ''));
            $this->fail('No wording: no consent');
        } catch (InputException) {
        }
        $this->assertSame([], $this->calls('record'));
    }

    /**
     * A wording with {{number}} is shown with the number in it, but the evidence is the configured wording: its hash is
     * what the browser sends, and it is stored as configured, with the number as the recipient.
     */
    public function testAWordingWithTheNumberPlaceholderIsHashedAndStoredAsConfigured(): void
    {
        $this->wording = 'Yes, text me offers at {{number}}. Reply STOP to stop.';
        $this->assertTrue($this->management()->recordGuest('sms_offer', 'masked', '+12025550123',
            hash('sha256', 'Yes, text me offers at {{number}}. Reply STOP to stop.')));
        $this->assertSame(['record', 'sms_offer', 'sms', '+12025550123', 2, 3,
            'Yes, text me offers at {{number}}. Reply STOP to stop.',
            'H(commercekings_notification_address_consent_ip|203.0.113.7)'], $this->calls('record')[0]);
        try {
            $this->management()->recordGuest('sms_offer', 'masked', '+12025550123',
                hash('sha256', 'Yes, text me offers at +1 202 555 0123. Reply STOP to stop.'));
            $this->fail('The rendered text is not the evidence');
        } catch (InputException $error) {
            $this->assertStringContainsString('changed', $error->getMessage());
        }
        $this->assertSame('{{number}}', \CommerceKings\Notification\Api\AddressConsentManagementInterface::NUMBER);
    }

    public function testUncheckingWithdrawsTheConsentAndItsSubscriptions(): void
    {
        $this->assertTrue($this->management()->recordGuest('sms_offer', 'masked', '+381601234567', '', false));
        $this->assertSame(['withdraw', 'sms', '+381601234567', 'sms_offer', 2], $this->calls('withdraw')[0]);
        $this->assertSame([['ac:old']], $this->removed);
        $this->assertSame([], $this->calls('record'));
    }

    public function testInvalidInputIsRefusedBeforeTheTypeIsAsked(): void
    {
        foreach ([['nope', '+381601234567'], ['plain', '+381601234567'], ['sms_offer', 'abc'], ['sms_offer', ['+381601234567']],
            ['sms_offer', '']] as [$type, $recipient]) {
            try {
                $this->management()->recordGuest($type, 'masked', $recipient, 'h');
                $this->fail('Expected Magento\'s 400 for ' . $type);
            } catch (InputException) {
            }
        }
        $this->assertSame([], $this->calls('authorize'));
    }

    public function testARefusedOrClosedReferenceRecordsNothing(): void
    {
        $this->target = null;
        $this->assertFalse($this->management()->recordGuest('sms_offer', 'foreign', '+381601234567', hash('sha256', self::WORDING)));
        $this->target = new Target(42, 3, false, '');
        $this->assertFalse($this->management()->recordGuest('sms_offer', 'masked', '+381601234567', hash('sha256', self::WORDING)));
        $this->assertFalse($this->management()->recordCustomer(0, 'sms_offer', '42', '+381601234567', hash('sha256', self::WORDING)));
        $this->assertSame([], $this->calls('record'));
    }

    public function testACustomerRecordsForTheirCart(): void
    {
        $this->assertTrue($this->management()->recordCustomer(17, 'sms_offer', '42', '+381601234567', hash('sha256', self::WORDING)));
        $this->assertSame(['authorize', '42', 17], $this->calls('authorize')[0]);
        $this->assertCount(1, $this->calls('record'));
    }
}
