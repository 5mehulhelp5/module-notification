<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Integration;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Cron\CleanUpAddressConsents;
use CommerceKings\Notification\Model\AddressConsent;
use CommerceKings\Notification\Model\AddressConsentManagement;
use CommerceKings\Notification\Model\AddressConsentRepository;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\PermissionResolver;
use CommerceKings\Notification\Model\StopList;
use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real database: an SMS type whose permission is address consent. A consent recorded for one number lets that number
 * subscribe and no other; unchecking or a stop withdraws it and ends the subscription; the cleanup keeps evidence
 * for the configured days.
 */
class AddressConsentTest extends TestCase
{
    private ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private string $code;
    private int $storeId;
    private int $referenceId;
    private string $number;
    /** another number of this run (the last digit changed) */
    private string $other;
    private AddressConsentManagement $consents;
    private SubscriptionManagement $subscriptions;
    private StopList $stopList;

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('frontend');
        $stores = $this->om->get(StoreManagerInterface::class);
        $stores->setCurrentStore('default');
        $this->storeId = (int)$stores->getStore()->getId();
        $this->resource = $this->om->get(ResourceConnection::class);
        $run = bin2hex(random_bytes(4));
        $this->code = 'ck_ac_' . $run;
        $this->referenceId = random_int(1000000000, 2000000000);
        $this->number = '+38160' . substr((string)hexdec($run), 0, 7);
        $this->other = substr($this->number, 0, -1) . (((int)substr($this->number, -1) + 1) % 10);

        $permission = $this->om->create(AddressConsent::class, ['typeCode' => $this->code]);
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('getPurpose')->willReturn('sms_offers');
        $type->method('getChannel')->willReturn('sms');
        $type->method('isSingle')->willReturn(true);
        $type->method('isStoppable')->willReturn(true);
        $type->method('getPermission')->willReturn($permission);
        $type->method('authorize')->willReturnCallback(fn () => new Target($this->referenceId, $this->storeId, true, ''));
        $types = new SubscriptionTypePool([$this->code => $type]);
        $shopWide = $this->createStub(SubscriptionPermissionInterface::class);
        $shopWide->method('check')->willReturn(Permission::refused());   // only the address consent may decide
        $shopWide->method('validCondition')->willReturn('1 = 0');
        $this->consents = $this->om->create(AddressConsentManagement::class, ['types' => $types]);
        $this->stopList = $this->om->create(StopList::class, ['types' => $types]);
        // no subscription observers: a provider module's opt-in confirmation would queue a real text to the number
        $this->subscriptions = $this->om->create(SubscriptionManagement::class, ['types' => $types,
            'permissions' => new PermissionResolver($shopWide, $types, $this->resource), 'stopList' => $this->stopList,
            'events' => $this->createStub(\Magento\Framework\Event\ManagerInterface::class)]);
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        $contacts = $db->fetchCol($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE),
            'contact_id')->where('type = ?', $this->code));
        $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE), ['type = ?' => $this->code]);
        $db->delete($this->resource->getTableName(AddressConsentRepository::TABLE), ['type = ?' => $this->code]);
        $this->stopList->resume('sms', $this->number);
        // the numbers' contacts, also of a sign-up removed during the test
        $website = (int)$this->om->get(StoreManagerInterface::class)->getStore($this->storeId)->getWebsiteId();
        foreach ([$this->number, $this->other] as $number) {
            $contacts[] = $this->om->get(ContactRepository::class)->find($website, $number, 'sms');
        }
        if (array_filter($contacts)) {
            $db->delete($this->resource->getTableName(ContactRepository::TABLE), ['contact_id IN (?)' => array_filter($contacts)]);
        }
    }

    private function wordingHash(): string
    {
        return hash('sha256', $this->om->get(Config::class)->getAddressConsentWording($this->storeId, 'sms'));
    }

    private function subscription(): ?array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE))
            ->where('type = ?', $this->code)) ?: null;
    }

    private function evidence(): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->resource->getTableName(AddressConsentRepository::TABLE))
            ->where('type = ?', $this->code)->order('consent_id'));
    }

    public function testAConsentForOneNumberLetsOnlyThatNumberSubscribe(): void
    {
        $this->assertFalse($this->subscriptions->subscribeGuest($this->code, 'ref', $this->number), 'no consent yet');
        $this->assertTrue($this->consents->recordGuest($this->code, 'ref', $this->number, $this->wordingHash()));
        $this->assertTrue($this->consents->recordGuest($this->code, 'ref', $this->number, $this->wordingHash()));
        $evidence = $this->evidence();
        $this->assertCount(1, $evidence, 'the same consent again: no duplicate');
        $this->assertSame([$this->storeId, 'sms'], [(int)$evidence[0]['store_id'], $evidence[0]['channel']]);
        $this->assertSame($this->om->get(Config::class)->getAddressConsentWording($this->storeId, 'sms'), $evidence[0]['wording']);
        $this->assertStringNotContainsString(substr($this->number, 1), implode('|', $evidence[0]), 'no plain number stored');

        $this->assertTrue($this->subscriptions->subscribeGuest($this->code, 'ref', $this->number));
        $this->assertSame($evidence[0]['reference'], $this->subscription()['permission_ref']);
        $this->assertFalse($this->subscriptions->subscribeGuest($this->code, 'ref', $this->other),
            'another number for the same cart');
        $this->assertNull($this->subscription(), 'and the previous sign-up of the cart is gone');
    }

    public function testUncheckingWithdrawsTheConsentAndEndsTheSubscription(): void
    {
        $this->consents->recordGuest($this->code, 'ref', $this->number, $this->wordingHash());
        $this->subscriptions->subscribeGuest($this->code, 'ref', $this->number);
        $this->assertTrue($this->consents->recordGuest($this->code, 'ref', $this->number, '', false));
        $this->assertNull($this->subscription());
        $this->assertNotNull($this->evidence()[0]['withdrawn_at'], 'the evidence stays, withdrawn');
        $this->assertFalse($this->subscriptions->subscribeGuest($this->code, 'ref', $this->number));
    }

    public function testAStopWithdrawsTheConsent(): void
    {
        $this->consents->recordGuest($this->code, 'ref', $this->number, $this->wordingHash());
        $this->subscriptions->subscribeGuest($this->code, 'ref', $this->number);
        $this->stopList->stop('sms', $this->number);   // e.g. the person replied STOP
        $this->assertNull($this->subscription());
        $this->assertNotNull($this->evidence()[0]['withdrawn_at']);
    }

    /** An undeliverable number is suppressed, but its consent stays for when it works again. */
    public function testAnUndeliverableNumberKeepsItsConsent(): void
    {
        $this->consents->recordGuest($this->code, 'ref', $this->number, $this->wordingHash());
        $this->stopList->stop('sms', $this->number, StopList::REASON_UNDELIVERABLE);
        $this->assertNull($this->evidence()[0]['withdrawn_at']);
        $this->stopList->resume('sms', $this->number);
        $this->assertTrue($this->subscriptions->subscribeGuest($this->code, 'ref', $this->number),
            'once lifted, the number subscribes on its consent');
    }

    public function testTheCleanupKeepsEvidenceForTheConfiguredDays(): void
    {
        $this->consents->recordGuest($this->code, 'ref', $this->number, $this->wordingHash());
        $this->consents->recordGuest($this->code, 'ref', $this->number, '', false);
        $this->consents->recordGuest($this->code, 'ref', $this->number, $this->wordingHash());
        $db = $this->resource->getConnection();
        $old = (int)$this->evidence()[0]['consent_id'];
        $db->update($this->resource->getTableName(AddressConsentRepository::TABLE),
            ['withdrawn_at' => new \Zend_Db_Expr('NOW() - INTERVAL 1826 DAY')], ['consent_id = ?' => $old]);
        $this->om->get(CleanUpAddressConsents::class)->execute();
        $left = array_map(fn ($row) => (int)$row['consent_id'], $this->evidence());
        $this->assertNotContains($old, $left, 'withdrawn longer than 1825 days ago: deleted');
        $this->assertCount(1, $left, 'the active consent stays');
    }
}
