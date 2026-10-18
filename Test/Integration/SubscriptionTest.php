<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Integration;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\ContactReferenceInterface;
use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Cron\CleanUpSubscriptions;
use CommerceKings\Notification\Cron\CleanUpContacts;
use CommerceKings\Notification\Model\ContactReferencePool;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\SubscriptionGuard;
use CommerceKings\Notification\Model\SubscriptionRemoval;
use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use CommerceKings\Notification\Model\SubscriptionContactReference;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\StringLength;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real SQL for ck_notification_subscription with a test type. The consent guard is a real SQL condition
 * on the consent id ('withdrawn' is invalid), so refusals happen inside the INSERT as in production.
 */
class SubscriptionTest extends TestCase
{
    private ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private StoreManagerInterface $stores;
    private string $type;
    private int $reference;
    private string $consentId = 'consent-a';
    private string $condition = '1 = 1';
    private bool $single = true;
    /** the reference the test type reports as deleted */
    private int $orphan = 0;
    private int $storeId;
    private array $customers = [];
    private array $websites = [];

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('frontend');
        $this->resource = $this->om->get(ResourceConnection::class);
        $this->stores = $this->om->get(StoreManagerInterface::class);
        $this->stores->setCurrentStore('default');
        $this->storeId = (int)$this->stores->getStore()->getId();
        $this->type = 'ck_test_' . bin2hex(random_bytes(4));
        $this->reference = random_int(100000000, 2000000000);
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        $contacts = $db->fetchCol($db->select()->from($this->table(SubscriptionManagement::TABLE), 'contact_id')
            ->where('type = ?', $this->type)->where('contact_id IS NOT NULL'));
        $db->delete($this->table(SubscriptionManagement::TABLE), ['type = ?' => $this->type]);
        $contacts = array_merge($contacts, $db->fetchCol($db->select()->from($this->table(ContactRepository::TABLE), 'contact_id')
            ->where('email LIKE ?', '%.' . $this->type . '@example.test')));
        if ($contacts) {
            $db->delete($this->table(ContactRepository::TABLE), ['contact_id IN (?)' => $contacts]);
        }
        $registry = $this->om->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        foreach ($this->customers as $id) {
            $this->om->get(CustomerRepositoryInterface::class)->deleteById($id);
        }
        $registry->unregister('isSecureArea');
        foreach ($this->websites as [$website, $group, $store]) {
            $db->delete($this->table('store'), ['store_id = ?' => $store]);
            $db->delete($this->table('store_group'), ['group_id = ?' => $group]);
            $db->delete($this->table('store_website'), ['website_id = ?' => $website]);
        }
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    private function email(string $name): string
    {
        return $name . '.' . $this->type . '@example.test';
    }

    private function management(?ContactRepositoryInterface $contacts = null): SubscriptionManagement
    {
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('getPurpose')->willReturn('test_purpose');
        $type->method('isSingle')->willReturnCallback(fn () => $this->single);
        $type->method('authorize')->willReturnCallback(fn () => new Target($this->reference, $this->storeId, true, $this->condition));
        $type->method('orphanCondition')->willReturnCallback(fn ($column) => $column . ' = ' . $this->orphan);
        $filters = $this->om->create(FilterManager::class);
        return new SubscriptionManagement($this->resource, new SubscriptionTypePool([$this->type => $type]),
            $this->permission(), $contacts ?? $this->om->get(ContactRepositoryInterface::class), $this->stores,
            new EmailAddress(), new StringLength(['max' => 255]), $filters,
            $this->om->get(\CommerceKings\Notification\Api\RecipientBlockInterface::class), $this->om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class));
    }

    private function afterContactSave(\Closure $afterSave): ContactRepositoryInterface
    {
        return new class($this->om->get(ContactRepositoryInterface::class), $afterSave) implements ContactRepositoryInterface {
            public function __construct(private ContactRepositoryInterface $actual, private \Closure $afterSave) {}
            public function save(int $websiteId, string $email): int
            {
                $id = $this->actual->save($websiteId, $email);
                ($this->afterSave)();
                return $id;
            }
            public function getEmail(int $contactId): ?string { return $this->actual->getEmail($contactId); }
        };
    }

    /** grants the current $consentId; the reference 'withdrawn' is revoked */
    private function permission(): SubscriptionPermissionInterface
    {
        $permission = $this->createStub(SubscriptionPermissionInterface::class);
        $permission->method('check')->willReturnCallback(fn () => Permission::granted($this->consentId));
        $permission->method('validCondition')->willReturnCallback(fn ($column) => '(' . $column . " <> 'withdrawn')");
        return $permission;
    }

    /** @return array<int, array<string, mixed>> this test's rows with their contact email */
    private function rows(): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from(['s' => $this->table(SubscriptionManagement::TABLE)])
            ->joinLeft(['n' => $this->table(ContactRepository::TABLE)], 'n.contact_id = s.contact_id', ['email'])
            ->where('s.type = ?', $this->type)->order('s.subscription_id'));
    }

    private function age(): void
    {
        $this->resource->getConnection()->update($this->table(SubscriptionManagement::TABLE),
            ['captured_at' => '2026-01-01 00:00:00'], ['type = ?' => $this->type]);
    }

    private function customer(): int
    {
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create()
            ->setEmail($this->email('customer-' . count($this->customers)))->setFirstname('Test')->setLastname('Customer')
            ->setWebsiteId((int)$this->stores->getStore()->getWebsiteId());
        $id = (int)$this->om->get(CustomerRepositoryInterface::class)->save($customer)->getId();
        $this->customers[] = $id;
        return $id;
    }

    public function testGuestSubscribesRetriesChangesAndUnsubscribes(): void
    {
        $management = $this->management();
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', ' ' . $this->email('first') . ' '));
        $this->age();
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('first')), 'identical retry');
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame($this->email('first'), $rows[0]['email']);
        $this->assertSame('2026-01-01 00:00:00', $rows[0]['captured_at'], 'a retry does not restart the delay');
        $this->assertSame('', $rows[0]['subject_key']);
        $this->assertSame((string)$this->reference, $rows[0]['reference_id']);

        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('second')));
        $rows = $this->rows();
        $this->assertCount(1, $rows, 'a single type replaces the email');
        $this->assertSame($this->email('second'), $rows[0]['email']);
        $this->assertNotSame('2026-01-01 00:00:00', $rows[0]['captured_at'], 'a change restarts the delay');

        $this->assertFalse($management->subscribeGuest($this->type, 'ref', ''));
        $this->assertSame([], $this->rows());
    }

    public function testWithdrawnConsentOrChangedReferenceWritesNothing(): void
    {
        $management = $this->management();
        $this->consentId = 'withdrawn';
        $this->assertFalse($management->subscribeGuest($this->type, 'ref', $this->email('guest')));
        $this->assertSame([], $this->rows());

        $this->consentId = 'consent-a';
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('guest')));
        $before = $this->rows();
        $this->condition = '0 = 1'; // e.g. the cart moved to another store after authorize()
        $this->consentId = 'consent-b';
        $this->assertFalse($management->subscribeGuest($this->type, 'ref', $this->email('other')));
        $this->assertFalse($management->subscribeGuest($this->type, 'ref', ''));
        $this->assertSame($before, $this->rows(), 'neither updated nor removed');
    }

    public function testOnePerPersonTypeKeepsEverySubscriber(): void
    {
        $this->single = false;
        $management = $this->management();
        $customer = $this->customer();
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('one')));
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('two')));
        $this->assertTrue($management->subscribeCustomer($customer, $this->type, 'ref'));
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('one')));
        $this->assertTrue($management->subscribeCustomer($customer, $this->type, 'ref'));
        $rows = $this->rows();
        $this->assertCount(3, $rows);
        $this->assertSame('u:' . $customer, $rows[2]['subject_key']);
        $this->assertNull($rows[2]['contact_id']);
        $this->assertFalse($management->subscribeGuest($this->type, 'ref', ''), 'needs the withdrawal link');
        $this->assertCount(3, $this->rows());
    }

    public function testWithdrawalGuardAndContactCleanup(): void
    {
        $management = $this->management();
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('guest')));
        $id = (int)$this->rows()[0]['subscription_id'];
        $guard = new SubscriptionGuard($this->resource, $this->permission());
        $this->assertTrue($guard->isValid($id, $this->type));
        $this->assertFalse($guard->isValid($id, 'other_type'));

        $contacts = $this->om->get(ContactRepositoryInterface::class);
        $website = (int)$this->stores->getStore()->getWebsiteId();
        $used = $contacts->save($website, $this->email('guest'));
        $unused = $contacts->save($website, $this->email('retyped'));
        $this->assertSame([$unused], $this->unusedContacts([$used, $unused]));

        // the permission 'consent-a' is revoked (with the consent bridge: its withdrawal handler does this)
        (new SubscriptionRemoval($this->resource))->removeByPermission(['consent-a']);
        $this->assertSame([], $this->rows());
        $this->assertFalse($guard->isValid($id, $this->type));
        $this->assertSame([$used, $unused], $this->unusedContacts([$used, $unused]));
    }

    public function testConcurrentCleanupCannotRemoveAnOldContactBeingReused(): void
    {
        $actual = $this->om->get(ContactRepositoryInterface::class);
        $email = $this->email('reused');
        $id = $actual->save((int)$this->stores->getStore()->getWebsiteId(), $email);
        $db = $this->resource->getConnection();
        $db->update($this->table(ContactRepository::TABLE),
            ['created_at' => new Expression('NOW() - INTERVAL 2 DAY')], ['contact_id = ?' => $id]);
        $peerResource = $this->om->create(ResourceConnection::class);
        $peer = $peerResource->getConnection();
        $this->assertNotSame($db->fetchOne('SELECT CONNECTION_ID()'), $peer->fetchOne('SELECT CONNECTION_ID()'));
        $peer->query('SET SESSION innodb_lock_wait_timeout = 1');
        $reference = new class($id, new SubscriptionContactReference($peerResource)) implements ContactReferenceInterface {
            public function __construct(private int $id, private SubscriptionContactReference $actual) {}
            public function usedCondition(string $column): string
            {
                // Restrict the real cleanup to this fixture; every other contact is treated as in use.
                return sprintf('%s <> %d OR (%s)', $column, $this->id, $this->actual->usedCondition($column));
            }
        };
        $cleanup = new CleanUpContacts($peerResource, new ContactReferencePool(['fixture' => $reference]));
        $blocked = false;
        $duringSave = function () use ($cleanup, &$blocked): void {
            try {
                $cleanup->execute();
            } catch (LockWaitException) {
                $blocked = true;
            }
        };
        $contacts = $this->afterContactSave($duringSave);
        try {
            $this->assertTrue($this->management($contacts)->subscribeGuest($this->type, 'ref', $email));
            $this->assertTrue($blocked, 'cleanup must wait until contact acquisition and subscription creation commit');
            $this->assertSame(0, $cleanup->execute(), 'after commit the subscription protects the reused contact');
            $this->assertSame($email, $actual->getEmail($id));
            $this->assertSame((string)$id, $this->rows()[0]['contact_id']);
            $this->assertSame(0, $db->getTransactionLevel());
        } finally {
            $peer->closeConnection();
        }
    }

    public function testConcurrentTargetChangeStillRefusesTheGuestWriteInsideItsTransaction(): void
    {
        $actual = $this->om->get(ContactRepositoryInterface::class);
        $markerEmail = $this->email('target-open');
        $markerId = $actual->save((int)$this->stores->getStore()->getWebsiteId(), $markerEmail);
        $db = $this->resource->getConnection();
        $this->condition = sprintf('EXISTS (SELECT 1 FROM %s AS target_marker WHERE target_marker.contact_id = %d AND %s)',
            $db->quoteIdentifier($this->table(ContactRepository::TABLE)), $markerId,
            $db->quoteInto('target_marker.email = ?', $markerEmail));
        $peer = $this->om->create(ResourceConnection::class)->getConnection();
        $changeTarget = fn () => $peer->update($this->table(ContactRepository::TABLE),
            ['email' => $this->email('target-closed')], ['contact_id = ?' => $markerId]);
        $contacts = $this->afterContactSave($changeTarget);
        try {
            $this->assertFalse($this->management($contacts)->subscribeGuest($this->type, 'ref', $this->email('guest')));
            $this->assertSame([], $this->rows(), 'the write must see the concurrently changed target');
            $this->assertSame(0, $db->getTransactionLevel());
        } finally {
            $peer->closeConnection();
        }
    }

    public function testWaitingCleanupKeepsTheSubscriptionAfterCaptureCommits(): void
    {
        $actual = $this->om->get(ContactRepositoryInterface::class);
        $email = $this->email('waiting-cleanup');
        $id = $actual->save((int)$this->stores->getStore()->getWebsiteId(), $email);
        $db = $this->resource->getConnection();
        $db->update($this->table(ContactRepository::TABLE),
            ['created_at' => new Expression('NOW() - INTERVAL 2 DAY')], ['contact_id = ?' => $id]);
        $process = null;
        $pipes = [];
        $contacts = $this->afterContactSave(function () use ($id, $db, &$process, &$pipes): void {
            $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', __DIR__ . '/contact-cleanup-worker.php',
                (string)$id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_timeout($pipes[1], 15);
            $connectionId = (int)trim((string)fgets($pipes[1]));
            $this->assertGreaterThan(0, $connectionId);
            $waiting = false;
            $deadline = microtime(true) + 5;
            do {
                foreach ($db->fetchAll('SHOW FULL PROCESSLIST') as $row) {
                    if ((int)$row['Id'] === $connectionId && str_starts_with((string)$row['Info'], 'DELETE n FROM ')) {
                        $waiting = true;
                        break;
                    }
                }
                if (!$waiting) { usleep(10000); }
            } while (!$waiting && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'the cleanup must already be waiting on the contact before capture commits');
        });
        try {
            $this->assertTrue($this->management($contacts)->subscribeGuest($this->type, 'ref', $email));
            $removed = trim((string)stream_get_contents($pipes[1]));
            $errors = stream_get_contents($pipes[2]);
            $this->assertSame('0', $removed, $errors);
            $this->assertSame($email, $actual->getEmail($id));
            $this->assertCount(1, $this->rows(), 'cleanup must not cascade-delete the newly committed subscription');
        } finally {
            foreach ($pipes as $pipe) { fclose($pipe); }
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) { proc_terminate($process); }
                proc_close($process);
            }
        }
    }

    /** Contacts the cleanup would remove (CleanUpContacts' condition, limited to these ids). */
    private function unusedContacts(array $ids): array
    {
        $db = $this->resource->getConnection();
        $used = array_map(fn ($reference) => '(' . $reference->usedCondition('n.contact_id') . ')',
            $this->om->get(ContactReferencePool::class)->getAll());
        return array_map('intval', $db->fetchCol($db->select()->from(['n' => $this->table(ContactRepository::TABLE)], 'contact_id')
            ->where('n.contact_id IN (?)', $ids)->where('NOT (' . implode(' OR ', $used) . ')')->order('n.contact_id')));
    }

    public function testDistinctAddressesNeverShareAContactOrARecipient(): void
    {
        $contacts = $this->om->get(ContactRepositoryInterface::class);
        $website = (int)$this->stores->getStore()->getWebsiteId();
        $plain = 'person.' . $this->type . '@example.com';
        $accented = 'person.' . $this->type . '@exámple.com';
        $plainId = $contacts->save($website, $plain);
        $accentedId = $contacts->save($website, $accented);
        $this->assertNotSame($plainId, $accentedId, 'the email column\'s collation would match these');
        $this->assertSame($plainId, $contacts->save($website, '  ' . strtoupper($plain) . ' '), 'case and spaces are one address');
        $this->assertSame($accented, $contacts->getEmail($accentedId));

        $this->single = false;
        $management = $this->management();
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $plain));
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $accented));
        $this->assertSame([$plain, $accented], array_column($this->rows(), 'email'), 'each subscription mails its own address');
        $this->resource->getConnection()->delete($this->table(ContactRepository::TABLE), ['contact_id IN (?)' => [$plainId, $accentedId]]);
    }

    public function testCleanupRemovesOnlyOrphanedSubscriptionsOfRegisteredTypes(): void
    {
        $this->single = false;
        $management = $this->management();
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('kept')));
        $kept = $this->reference;
        $this->reference++;
        $this->assertTrue($management->subscribeGuest($this->type, 'ref', $this->email('gone')));
        $this->orphan = $this->reference;
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('orphanCondition')->willReturnCallback(fn ($column) => $column . ' = ' . $this->orphan);
        $cleanup = new CleanUpSubscriptions($this->resource, new SubscriptionTypePool([$this->type => $type]));
        $this->assertSame(1, $cleanup->execute());
        $this->assertSame([(string)$kept], array_column($this->rows(), 'reference_id'));
        $this->assertSame(0, (new CleanUpSubscriptions($this->resource, new SubscriptionTypePool([])))->execute(),
            'an unregistered type is left alone');
    }

    public function testDeletingTheStoreOrCustomerRemovesTheirSubscriptions(): void
    {
        $db = $this->resource->getConnection();
        $code = 'ck_ns_' . bin2hex(random_bytes(4));
        $db->insert($this->table('store_website'), ['code' => $code, 'name' => 'Subscription test', 'is_default' => 0]);
        $website = (int)$db->lastInsertId($this->table('store_website'));
        $db->insert($this->table('store_group'), ['code' => $code, 'website_id' => $website, 'name' => 'Subscription test',
            'root_category_id' => 2, 'default_store_id' => 0]);
        $group = (int)$db->lastInsertId($this->table('store_group'));
        $db->insert($this->table('store'), ['code' => $code, 'website_id' => $website, 'group_id' => $group,
            'name' => 'Subscription test', 'is_active' => 1]);
        $store = (int)$db->lastInsertId($this->table('store'));
        $this->websites[] = [$website, $group, $store];
        $this->stores->reinitStores();

        $this->single = false;
        $customer = $this->customer();
        $this->assertTrue($this->management()->subscribeCustomer($customer, $this->type, 'ref'));
        $this->storeId = $store;
        $this->assertTrue($this->management()->subscribeGuest($this->type, 'ref', $this->email('foreign')));
        $this->assertCount(2, $this->rows());

        $db->delete($this->table('store'), ['store_id = ?' => $store]);
        $this->assertCount(1, $this->rows());
        $registry = $this->om->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        $this->om->get(CustomerRepositoryInterface::class)->deleteById($customer);
        $registry->unregister('isSecureArea');
        $this->customers = [];
        $this->assertSame([], $this->rows());
    }
}
