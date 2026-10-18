<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Integration;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\Cooldown;
use CommerceKings\Notification\Model\KeyChangeCleanup;
use CommerceKings\Notification\Model\KeyFingerprint;
use CommerceKings\Notification\Model\RecipientBlock;
use CommerceKings\Notification\Model\SubscriptionManagement;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** Do not contact against real SQL: hashes, signed links, the lift timer and removed subscriptions. */
class RecipientBlockTest extends TestCase
{
    private ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private RecipientBlock $blocks;
    private string $run;
    private array $customers = [];
    private array $references = [];

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('frontend');
        $this->om->get(StoreManagerInterface::class)->setCurrentStore('default');
        $this->resource = $this->om->get(ResourceConnection::class);
        $this->blocks = $this->om->get(RecipientBlock::class);
        $this->run = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        foreach ([Channel::EMAIL => [$this->email('a'), $this->email('b'), $this->email('c')], 'text' => ['+381601234567']]
            as $channel => $recipients) {
            foreach ($recipients as $recipient) {
                $this->blocks->unblock($channel, $recipient);
            }
        }
        if ($this->references) {
            $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE), ['reference_id IN (?)' => $this->references]);
        }
        $db->delete($this->resource->getTableName(ContactRepository::TABLE), ['email LIKE ?' => 'ck-block-' . $this->run . '-%']);
        $registry = $this->om->get(Registry::class);
        $registry->register('isSecureArea', true, true);
        foreach ($this->customers as $id) {
            $this->om->get(CustomerRepositoryInterface::class)->deleteById($id);
        }
        $registry->unregister('isSecureArea');
    }

    private function email(string $name): string
    {
        return 'ck-block-' . $this->run . '-' . $name . '@example.test';
    }

    public function testBlockIsPerRecipientAndChannelAndIgnoresCaseAndSpaces(): void
    {
        $this->assertFalse($this->blocks->isBlocked(Channel::EMAIL, $this->email('a')));
        $this->blocks->block(Channel::EMAIL, '  ' . strtoupper($this->email('a')) . ' ');
        $this->assertTrue($this->blocks->isBlocked(Channel::EMAIL, $this->email('a')), 'one address, however typed');
        $this->assertFalse($this->blocks->isBlocked(Channel::EMAIL, $this->email('b')));
        $this->assertFalse($this->blocks->isBlocked('text', $this->email('a')), 'channels are separate');
        $hashes = $this->resource->getConnection()->fetchCol('SELECT recipient_hash FROM '
            . $this->resource->getTableName(RecipientBlock::TABLE));
        foreach ($hashes as $hash) {
            $this->assertStringNotContainsString('@', $hash, 'never the address');
        }
        $this->blocks->unblock(Channel::EMAIL, $this->email('a'));
        $this->assertFalse($this->blocks->isBlocked(Channel::EMAIL, $this->email('a')));
    }

    public function testSignedLinkRoundTripsAndAlteredLinksAreRejected(): void
    {
        $url = $this->blocks->getBlockUrl(Channel::EMAIL, $this->email('a'), 1);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('/cknotification/block', $url);
        $this->assertStringNotContainsString(rawurlencode($this->email('a')), $url, 'the address is encrypted');
        $this->assertSame(['channel' => Channel::EMAIL, 'recipient' => $this->email('a')],
            $this->blocks->fromLink($query['r'], $query['s']));
        $this->assertNull($this->blocks->fromLink($query['r'], str_repeat('0', 64)), 'forged signature');
        $this->assertNull($this->blocks->fromLink($query['r'] . 'x', $query['s']), 'altered recipient');
        $this->assertNull($this->blocks->fromLink('', ''));
    }

    public function testTouchRestartsTheTimerAndCleanupLiftsOnlyQuietBlocks(): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(RecipientBlock::TABLE);
        $this->assertFalse($this->blocks->touch(Channel::EMAIL, $this->email('a')), 'not blocked: nothing to touch');
        $this->blocks->block(Channel::EMAIL, $this->email('a'));
        $this->blocks->block(Channel::EMAIL, $this->email('b'));
        $db->update($table, ['last_attempt_at' => new \Zend_Db_Expr('NOW() - INTERVAL 400 DAY')]);
        $this->assertTrue($this->blocks->touch(Channel::EMAIL, $this->email('a')), 'an attempt keeps a\'s block alive');
        $this->blocks->cleanUp();
        $this->assertTrue($this->blocks->isBlocked(Channel::EMAIL, $this->email('a')));
        $this->assertFalse($this->blocks->isBlocked(Channel::EMAIL, $this->email('b')), 'quiet for too long: lifted');
    }

    public function testBlockRemovesEveryGuestAndCustomerSubscriptionOfTheAddress(): void
    {
        $db = $this->resource->getConnection();
        $contactId = $this->om->get(ContactRepository::class)->save(1, $this->email('a'));
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create()->setWebsiteId(1)
            ->setEmail(strtoupper($this->email('a')))->setFirstname('Block')->setLastname('Test');
        $customerId = (int)$this->om->get(CustomerRepositoryInterface::class)->save($customer)->getId();
        $this->customers[] = $customerId;
        $otherContact = $this->om->get(ContactRepository::class)->save(1, $this->email('c'));
        $rows = [['contact_id' => $contactId, 'customer_id' => null], ['contact_id' => null, 'customer_id' => $customerId],
            ['contact_id' => $otherContact, 'customer_id' => null]];
        foreach ($rows as $row) {
            $this->references[] = $reference = random_int(1000000000, 2000000000);
            $db->insert($this->resource->getTableName(SubscriptionManagement::TABLE), $row + ['type' => 'ck_block_test',
                'reference_id' => $reference, 'store_id' => 1, 'subject_key' => '', 'permission_ref' => '']);
        }
        $this->blocks->block(Channel::EMAIL, $this->email('a'));
        $left = $db->fetchAll($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE),
            ['contact_id', 'customer_id'])->where('reference_id IN (?)', $this->references));
        $this->assertSame([['contact_id' => (string)$otherContact, 'customer_id' => null]], $left,
            'the guest and the customer subscription of the address are gone; another address keeps its own');
    }

    public function testRowsCarryTheCurrentKeyFingerprint(): void
    {
        $db = $this->resource->getConnection();
        $current = $this->om->get(KeyFingerprint::class)->current();
        $this->blocks->block(Channel::EMAIL, $this->email('a'));
        $this->assertSame($current, $db->fetchOne($db->select()->from($this->resource->getTableName(RecipientBlock::TABLE), 'key_id')
            ->where('recipient_hash = ?', $this->hashOf($this->email('a')))));
        $cooldown = $this->om->get(Cooldown::class);
        $token = $cooldown->claim($this->email('b'));
        $this->assertNotNull($token);
        $this->assertSame($current, $db->fetchOne($db->select()->from($this->resource->getTableName('ck_notification_cooldown'), 'key_id')
            ->where('recipient_hash = ?', $cooldown->hash($this->email('b')))));
        $cooldown->release($this->email('b'), $token);
    }

    public function testAWriteMarksARowThatHadNoFingerprint(): void
    {
        $db = $this->resource->getConnection();
        $current = $this->om->get(KeyFingerprint::class)->current();
        $blocks = $this->resource->getTableName(RecipientBlock::TABLE);
        $cooldowns = $this->resource->getTableName('ck_notification_cooldown');
        $cooldown = $this->om->get(Cooldown::class);
        $db->insert($blocks, ['recipient_hash' => $this->hashOf($this->email('a')), 'key_id' => null]);
        $db->insert($cooldowns, ['recipient_hash' => $cooldown->hash($this->email('b')), 'key_id' => null,
            'sent_at' => new \Zend_Db_Expr('NOW() - INTERVAL 400 DAY')]);
        try {
            $this->blocks->block(Channel::EMAIL, $this->email('a'));
            $this->assertNotNull($token = $cooldown->claim($this->email('b')), 'an expired claim is renewed');
            $this->assertSame($current, $db->fetchOne($db->select()->from($blocks, 'key_id')
                ->where('recipient_hash = ?', $this->hashOf($this->email('a')))), 'blocking again marks the row');
            $this->assertSame($current, $db->fetchOne($db->select()->from($cooldowns, 'key_id')
                ->where('recipient_hash = ?', $cooldown->hash($this->email('b')))), 'a renewed claim marks the row');
            $cooldown->release($this->email('b'), $token);
        } finally {
            $db->delete($cooldowns, ['recipient_hash = ?' => $cooldown->hash($this->email('b'))]);
        }
    }

    /** A cleanup that treats 'earlier-key-id' as an older key still in env.php. */
    private function cleanup(): KeyChangeCleanup
    {
        $fingerprint = $this->createStub(KeyFingerprint::class);
        $fingerprint->method('current')->willReturn($this->om->get(KeyFingerprint::class)->current());
        $fingerprint->method('earlier')->willReturn(['earlier-key-id']);
        return $this->om->create(KeyChangeCleanup::class, ['fingerprint' => $fingerprint]);
    }

    /** @return array{0: string[], 1: string, 2: string, 3: string} earlier-key, unknown-key and unmarked blocks, an earlier-key cooldown */
    private function keyChangeFixture(): array
    {
        $db = $this->resource->getConnection();
        $blocks = $this->resource->getTableName(RecipientBlock::TABLE);
        $this->blocks->block(Channel::EMAIL, $this->email('a'));                 // current key
        $earlier = ['ck_old_' . $this->run . '_1', 'ck_old_' . $this->run . '_2'];
        foreach ($earlier as $hash) {
            $db->insert($blocks, ['recipient_hash' => $hash, 'key_id' => 'earlier-key-id']);
        }
        $unknown = 'ck_gone_' . $this->run;                                       // a key no longer in env.php
        $db->insert($blocks, ['recipient_hash' => $unknown, 'key_id' => 'gone-key-id']);
        $unmarked = 'ck_nokey_' . $this->run;                                     // written by older code
        $db->insert($blocks, ['recipient_hash' => $unmarked, 'key_id' => null]);
        $cooldown = 'ck_old_' . $this->run . '_c';
        $db->insert($this->resource->getTableName(Cooldown::TABLE), ['recipient_hash' => $cooldown, 'key_id' => 'earlier-key-id']);
        return [$earlier, $unknown, $unmarked, $cooldown];
    }

    private function removeKeyChangeFixture(array $fixture): void
    {
        [$earlier, $unknown, $unmarked, $cooldown] = $fixture;
        $db = $this->resource->getConnection();
        $db->delete($this->resource->getTableName(RecipientBlock::TABLE), ['recipient_hash IN (?)' => [...$earlier, $unknown, $unmarked]]);
        $db->delete($this->resource->getTableName(Cooldown::TABLE), ['recipient_hash = ?' => $cooldown]);
    }

    private function blockExists(string $hash): bool
    {
        $db = $this->resource->getConnection();
        return (bool)$db->fetchOne($db->select()->from($this->resource->getTableName(RecipientBlock::TABLE), 'recipient_hash')
            ->where('recipient_hash = ?', $hash));
    }

    public function testKeyChangeRemovesEarlierKeysThenUnknownOnlyOnRequestAndNeverCurrentOrUnmarked(): void
    {
        $fixture = $this->keyChangeFixture();
        [$earlier, $unknown, $unmarked, $cooldown] = $fixture;
        $inbox = $this->resource->getTableName('adminnotification_inbox');
        $db = $this->resource->getConnection();
        $lastNotice = (int)$db->fetchOne("SELECT IFNULL(MAX(notification_id), 0) FROM {$inbox}");
        try {
            $counts = $this->cleanup()->count();
            $this->assertGreaterThanOrEqual(2, $counts['blocks']['earlier']);
            $this->assertGreaterThanOrEqual(1, $counts['blocks']['unknown']);
            $this->assertGreaterThanOrEqual(1, $counts['blocks']['unmarked']);
            $this->assertGreaterThanOrEqual(1, $counts['blocks']['current']);
            $this->assertGreaterThanOrEqual(1, $counts['cooldowns']['earlier']);
            $this->assertTrue($this->blockExists($earlier[0]), 'counting removes nothing');

            $removed = $this->cleanup()->execute();
            $this->assertGreaterThanOrEqual(2, $removed['blocks']);
            $this->assertFalse($this->blockExists($earlier[0]) || $this->blockExists($earlier[1]), 'earlier-key blocks removed');
            $this->assertSame(0, (int)$db->fetchOne($db->select()->from($this->resource->getTableName(Cooldown::TABLE), 'COUNT(*)')
                ->where('recipient_hash = ?', $cooldown)), 'earlier-key cooldown removed');
            $this->assertTrue($this->blockExists($unknown), 'an unknown key stays without --force');
            $this->assertTrue($this->blockExists($unmarked));
            $this->assertTrue($this->blocks->isBlocked(Channel::EMAIL, $this->email('a')), 'the current block stays');

            $this->cleanup()->execute(true);
            $this->assertFalse($this->blockExists($unknown), '--force removes the unknown key');
            $this->assertTrue($this->blockExists($unmarked), 'a row without a fingerprint is never removed');
            $this->assertTrue($this->blocks->isBlocked(Channel::EMAIL, $this->email('a')));
        } finally {
            $this->removeKeyChangeFixture($fixture);
            $db->delete($inbox, ['notification_id > ?' => $lastNotice, 'title LIKE ?' => '%encryption key was changed%']);
        }
    }

    public function testKeyChangeNotifiesTheAdminOnce(): void
    {
        if (!$this->om->get(\Magento\Framework\Module\Manager::class)->isEnabled('Magento_AdminNotification')) {
            $this->markTestSkipped('Magento_AdminNotification is disabled: the notice has no inbox to check.');
        }
        $fixture = $this->keyChangeFixture();
        $db = $this->resource->getConnection();
        $inbox = $this->resource->getTableName('adminnotification_inbox');
        $lastNotice = (int)$db->fetchOne("SELECT IFNULL(MAX(notification_id), 0) FROM {$inbox}");
        $notices = fn () => $db->fetchAll($db->select()->from($inbox)->where('notification_id > ?', $lastNotice)
            ->where('title LIKE ?', '%encryption key was changed%'));
        try {
            $this->assertTrue($this->cleanup()->execute()['notified']);
            $this->assertCount(1, $notices(), 'one admin notification');
            $this->assertStringContainsString('do-not-contact block(s) were lifted', $notices()[0]['description']);
            $this->assertFalse($this->cleanup()->execute()['notified'], 'nothing earlier left');
            $this->assertCount(1, $notices(), 'no second notification');
        } finally {
            $this->removeKeyChangeFixture($fixture);
            $db->delete($inbox, ['notification_id > ?' => $lastNotice, 'title LIKE ?' => '%encryption key was changed%']);
        }
    }

    public function testEarlierKeysAreReadFromTheRealKeyFormat(): void
    {
        $real = (string)$this->om->get(\Magento\Framework\App\DeploymentConfig::class)->get('crypt/key');
        $config = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $config->method('get')->willReturn(str_repeat('o', 32) . "\n" . $real);
        $fingerprint = new KeyFingerprint($this->om->get(\Magento\Framework\Encryption\EncryptorInterface::class), $config);
        $this->assertCount(1, $fingerprint->earlier(), 'computing the newest key like the Encryptor is confirmed');
        $this->assertSame([], $this->om->get(KeyFingerprint::class)->earlier(), 'this install has one key');
    }

    public function testTheCommandIsRegistered(): void
    {
        $commands = $this->om->get(\Magento\Framework\Console\CommandListInterface::class)->getCommands();
        $command = $commands['notification_key_change_cleanup'] ?? null;
        $this->assertInstanceOf(\CommerceKings\Notification\Console\Command\KeyChangeCleanup::class, $command);
        $this->assertSame('notification:key-change:cleanup', $command->getName());
    }

    private function hashOf(string $email): string
    {
        return (fn () => $this->hash(Channel::EMAIL, $email))->call($this->blocks);
    }
}
