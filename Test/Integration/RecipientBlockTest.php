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
        $this->assertSame(['channel' => Channel::EMAIL, 'recipient' => $this->email('a'), 'current' => true],
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

    public function testKeyChangeCleanupKeepsBlocksOfListedKeysAndRemovesUnknownOnesOnlyOnRequest(): void
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
            $this->assertSame(0, $removed['blocks'], 'by default no block is removed');
            $this->assertTrue($this->blockExists($earlier[0]) && $this->blockExists($earlier[1]),
                'blocks of an earlier key still in env.php keep working, so they stay');
            $this->assertSame(0, (int)$db->fetchOne($db->select()->from($this->resource->getTableName(Cooldown::TABLE), 'COUNT(*)')
                ->where('recipient_hash = ?', $cooldown)), 'earlier-key cooldown removed');
            $this->assertTrue($this->blockExists($unknown), 'an unknown key stays without --force');
            $this->assertTrue($this->blockExists($unmarked));
            $this->assertTrue($this->blocks->isBlocked(Channel::EMAIL, $this->email('a')), 'the current block stays');

            $this->cleanup()->execute(true);
            $this->assertFalse($this->blockExists($unknown), '--force removes the unknown key');
            $this->assertTrue($this->blockExists($earlier[0]), '...but never a block of a key still listed');
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
            $this->assertFalse($this->cleanup()->execute()['notified'], 'the default lifts no block, so no notice');
            $this->assertTrue($this->cleanup()->execute(true)['notified']);
            $this->assertCount(1, $notices(), 'one admin notification');
            $this->assertStringContainsString('do-not-contact block(s) of encryption keys no longer in env.php were lifted',
                $notices()[0]['description']);
            $this->assertFalse($this->cleanup()->execute(true)['notified'], 'nothing unknown left');
            $this->assertCount(1, $notices(), 'no second notification');
        } finally {
            $this->removeKeyChangeFixture($fixture);
            $db->delete($inbox, ['notification_id > ?' => $lastNotice, 'title LIKE ?' => '%encryption key was changed%']);
        }
    }

    /** After encryption:key:change: a block stored and a stop link signed under an older key, both still listed. */
    public function testABlockAndLinkOfAnOlderKeyKeepWorkingAndTheBlockMovesToTheNewestKey(): void
    {
        $real = (string)$this->om->get(\Magento\Framework\App\DeploymentConfig::class)->get('crypt/key');
        $oldKey = 'ck-test-old-key-' . $this->run . '0123456789ab';
        $config = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $config->method('get')->willReturn($oldKey . "\n" . $real);
        $encryptor = $this->om->get(\Magento\Framework\Encryption\EncryptorInterface::class);
        $blocks = $this->om->create(RecipientBlock::class,
            ['keyedHash' => new \CommerceKings\Notification\Model\KeyedHash($encryptor, $config)]);
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(RecipientBlock::TABLE);
        $address = $this->email('b');
        $oldHash = hash_hmac('sha256', 'commercekings_notification_block|email|' . $address, $oldKey);
        $db->insert($table, ['recipient_hash' => $oldHash, 'key_id' => 'old-key-fp',
            'last_attempt_at' => new \Zend_Db_Expr('NOW() - INTERVAL 10 DAY')]);
        try {
            $this->assertTrue($blocks->isBlocked(Channel::EMAIL, $address), 'the old block still blocks');
            $this->assertFalse((bool)$db->fetchOne($db->select()->from($table, 'recipient_hash')
                ->where('recipient_hash = ?', $oldHash)), 'the old row is gone...');
            $this->assertTrue($this->blocks->isBlocked(Channel::EMAIL, $address),
                '...moved to the newest key, so it works even without the old key');
            $this->assertSame($this->om->get(KeyFingerprint::class)->current(), $db->fetchOne($db->select()
                ->from($table, 'key_id')->where('recipient_hash = ?', $this->hashOf($address))));

            $url = $this->blocks->getBlockUrl(Channel::EMAIL, $address, 1);
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $oldSignature = hash_hmac('sha256', 'commercekings_notification_block_link|' . $query['r'], $oldKey);
            $this->assertSame(['channel' => Channel::EMAIL, 'recipient' => $address, 'current' => false],
                $blocks->fromLink($query['r'], $oldSignature), 'a stop link from an email sent before the change');
            $this->assertSame(['channel' => Channel::EMAIL, 'recipient' => $address, 'current' => true],
                $blocks->fromLink($query['r'], $query['s']));
            $this->assertNull($this->blocks->fromLink($query['r'], $oldSignature), 'with the old key removed: invalid');
        } finally {
            $db->delete($table, ['recipient_hash IN (?)' => [$oldHash, $this->hashOf($address)]]);
        }
    }

    public function testEarlierKeysAreReadFromTheRealKeyFormat(): void
    {
        $real = (string)$this->om->get(\Magento\Framework\App\DeploymentConfig::class)->get('crypt/key');
        $config = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $config->method('get')->willReturn(str_repeat('o', 32) . "\n" . $real);
        $fingerprint = new KeyFingerprint($this->om->get(\Magento\Framework\Encryption\EncryptorInterface::class), $config);
        $installed = count(preg_split('/\s+/', trim($real)));
        $this->assertCount($installed, $fingerprint->earlier(),
            'computing the newest key like the Encryptor is confirmed: the extra key plus the install\'s older ones');
        $this->assertCount($installed - 1, $this->om->get(KeyFingerprint::class)->earlier(),
            'the install\'s own older keys (none on a fresh install)');
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
        return (fn () => $this->hashes(Channel::EMAIL, $email)[0])->call($this->blocks);
    }
}
