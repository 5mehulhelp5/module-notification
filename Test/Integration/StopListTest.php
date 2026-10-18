<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Integration;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\StopList;
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
class StopListTest extends TestCase
{
    private ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private StopList $stopList;
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
        $this->stopList = $this->om->get(StopList::class);
        $this->run = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        foreach ([Channel::EMAIL => [$this->email('a'), $this->email('b'), $this->email('c')], 'text' => ['+381601234567']]
            as $channel => $recipients) {
            foreach ($recipients as $recipient) {
                $this->stopList->resume($channel, $recipient);
            }
        }
        if ($this->references) {
            $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE), ['reference_id IN (?)' => $this->references]);
        }
        $db->delete($this->resource->getTableName(ContactRepository::TABLE), ['recipient LIKE ?' => 'ck-stop-' . $this->run . '-%']);
        $registry = $this->om->get(Registry::class);
        $registry->register('isSecureArea', true, true);
        foreach ($this->customers as $id) {
            $this->om->get(CustomerRepositoryInterface::class)->deleteById($id);
        }
        $registry->unregister('isSecureArea');
    }

    private function email(string $name): string
    {
        return 'ck-stop-' . $this->run . '-' . $name . '@example.test';
    }

    public function testBlockIsPerRecipientAndChannelAndIgnoresCaseAndSpaces(): void
    {
        $this->assertFalse($this->stopList->isStopped(Channel::EMAIL, $this->email('a')));
        $this->stopList->stop(Channel::EMAIL, '  ' . strtoupper($this->email('a')) . ' ');
        $this->assertTrue($this->stopList->isStopped(Channel::EMAIL, $this->email('a')), 'one address, however typed');
        $this->assertFalse($this->stopList->isStopped(Channel::EMAIL, $this->email('b')));
        $this->assertFalse($this->stopList->isStopped('text', $this->email('a')), 'channels are separate');
        $hashes = $this->resource->getConnection()->fetchCol('SELECT recipient_hash FROM '
            . $this->resource->getTableName(StopList::TABLE));
        foreach ($hashes as $hash) {
            $this->assertStringNotContainsString('@', $hash, 'never the address');
        }
        $this->stopList->resume(Channel::EMAIL, $this->email('a'));
        $this->assertFalse($this->stopList->isStopped(Channel::EMAIL, $this->email('a')));
    }

    public function testSignedLinkRoundTripsAndAlteredLinksAreRejected(): void
    {
        $url = $this->stopList->getStopUrl(Channel::EMAIL, $this->email('a'), 1);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('/cknotification/stop', $url);
        $this->assertStringNotContainsString(rawurlencode($this->email('a')), $url, 'the address is encrypted');
        $this->assertSame(['channel' => Channel::EMAIL, 'recipient' => $this->email('a'), 'current' => true],
            $this->stopList->fromLink($query['r'], $query['s']));
        $this->assertNull($this->stopList->fromLink($query['r'], str_repeat('0', 64)), 'forged signature');
        $this->assertNull($this->stopList->fromLink($query['r'] . 'x', $query['s']), 'altered recipient');
        $this->assertNull($this->stopList->fromLink('', ''));
    }

    public function testTouchRestartsTheTimerAndCleanupLiftsOnlyQuietBlocks(): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(StopList::TABLE);
        $this->assertFalse($this->stopList->touch(Channel::EMAIL, $this->email('a')), 'not stopped: nothing to touch');
        $this->stopList->stop(Channel::EMAIL, $this->email('a'));
        $this->stopList->stop(Channel::EMAIL, $this->email('b'));
        $db->update($table, ['last_attempt_at' => new \Zend_Db_Expr('NOW() - INTERVAL 400 DAY')]);
        $this->assertTrue($this->stopList->touch(Channel::EMAIL, $this->email('a')), 'an attempt keeps a\'s stop alive');
        $this->stopList->cleanUp();
        $this->assertTrue($this->stopList->isStopped(Channel::EMAIL, $this->email('a')));
        $this->assertFalse($this->stopList->isStopped(Channel::EMAIL, $this->email('b')), 'quiet for too long: lifted');
    }

    /** A STOP reply holds until START; link and undeliverable stops lift; a stop keeps its strongest reason. */
    public function testAStopReplyHoldsUntilResumeAndAStopKeepsItsStrongestReason(): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(StopList::TABLE);
        $hash = fn (string $recipient) => (fn () => $this->hashes(Channel::EMAIL, $recipient)[0])->call($this->stopList);
        $this->stopList->stop(Channel::EMAIL, $this->email('a'), StopList::REASON_REPLY);
        $this->stopList->stop(Channel::EMAIL, $this->email('b'), StopList::REASON_UNDELIVERABLE);
        $this->stopList->stop(Channel::EMAIL, $this->email('c'));
        $this->assertSame([StopList::REASON_REPLY, StopList::REASON_UNDELIVERABLE, StopList::REASON_LINK],
            array_map(fn ($name) => $this->stopList->getReason(Channel::EMAIL, $this->email($name)), ['a', 'b', 'c']));

        $this->stopList->stop(Channel::EMAIL, $this->email('a'), StopList::REASON_UNDELIVERABLE);
        $this->stopList->stop(Channel::EMAIL, $this->email('a'));
        $this->assertSame(StopList::REASON_REPLY, $this->stopList->getReason(Channel::EMAIL, $this->email('a')),
            'a reply is never weakened');
        $this->stopList->stop(Channel::EMAIL, $this->email('b'));
        $this->assertSame(StopList::REASON_LINK, $this->stopList->getReason(Channel::EMAIL, $this->email('b')),
            'a stronger reason replaces a weaker one');

        $db->update($table, ['last_attempt_at' => new \Zend_Db_Expr('NOW() - INTERVAL 400 DAY')],
            ['recipient_hash IN (?)' => [$hash($this->email('a')), $hash($this->email('b')), $hash($this->email('c'))]]);
        $this->stopList->cleanUp();
        $this->assertTrue($this->stopList->isStopped(Channel::EMAIL, $this->email('a')), 'a STOP reply is not lifted by time');
        $this->assertFalse($this->stopList->isStopped(Channel::EMAIL, $this->email('b')));
        $this->assertFalse($this->stopList->isStopped(Channel::EMAIL, $this->email('c')));
        $this->stopList->resume(Channel::EMAIL, $this->email('a'));
        $this->assertNull($this->stopList->getReason(Channel::EMAIL, $this->email('a')), 'START lifts it');
    }

    public function testBlockRemovesEveryGuestAndCustomerSubscriptionOfTheAddress(): void
    {
        $db = $this->resource->getConnection();
        $contactId = $this->om->get(ContactRepository::class)->save(1, $this->email('a'));
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create()->setWebsiteId(1)
            ->setEmail(strtoupper($this->email('a')))->setFirstname('Stop')->setLastname('Test');
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
        $this->stopList->stop(Channel::EMAIL, $this->email('a'));
        $left = $db->fetchAll($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE),
            ['contact_id', 'customer_id'])->where('reference_id IN (?)', $this->references));
        $this->assertSame([['contact_id' => (string)$otherContact, 'customer_id' => null]], $left,
            'the guest and the customer subscription of the address are gone; another address keeps its own');
    }

    /** After encryption:key:change: a stop stored and a stop link signed under an older key, both still listed. */
    public function testABlockAndLinkOfAnOlderKeyKeepWorkingAndTheBlockMovesToTheNewestKey(): void
    {
        $real = (string)$this->om->get(\Magento\Framework\App\DeploymentConfig::class)->get('crypt/key');
        $oldKey = 'ck-test-old-key-' . $this->run . '0123456789ab';
        $config = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $config->method('get')->willReturn($oldKey . "\n" . $real);
        $encryptor = $this->om->get(\Magento\Framework\Encryption\EncryptorInterface::class);
        $stopList = $this->om->create(StopList::class,
            ['keyedHash' => new \CommerceKings\Notification\Model\KeyedHash($encryptor, $config)]);
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(StopList::TABLE);
        $address = $this->email('b');
        $oldHash = hash_hmac('sha256', 'commercekings_notification_stop|email|' . $address, $oldKey);
        $db->insert($table, ['recipient_hash' => $oldHash,
            'last_attempt_at' => new \Zend_Db_Expr('NOW() - INTERVAL 10 DAY')]);
        try {
            $this->assertTrue($stopList->isStopped(Channel::EMAIL, $address), 'the old stop still applies');
            $this->assertFalse((bool)$db->fetchOne($db->select()->from($table, 'recipient_hash')
                ->where('recipient_hash = ?', $oldHash)), 'the old row is gone...');
            $this->assertTrue($this->stopList->isStopped(Channel::EMAIL, $address),
                '...moved to the newest key, so it works even without the old key');

            $url = $this->stopList->getStopUrl(Channel::EMAIL, $address, 1);
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $oldSignature = hash_hmac('sha256', 'commercekings_notification_stop_link|' . $query['r'], $oldKey);
            $this->assertSame(['channel' => Channel::EMAIL, 'recipient' => $address, 'current' => false],
                $stopList->fromLink($query['r'], $oldSignature), 'a stop link from an email sent before the change');
            $this->assertSame(['channel' => Channel::EMAIL, 'recipient' => $address, 'current' => true],
                $stopList->fromLink($query['r'], $query['s']));
            $this->assertNull($this->stopList->fromLink($query['r'], $oldSignature), 'with the old key removed: invalid');
        } finally {
            $db->delete($table, ['recipient_hash IN (?)' => [$oldHash, $this->hashOf($address)]]);
        }
    }

    public function testOlderKeysAreReadFromTheRealKeyFormat(): void
    {
        $real = (string)$this->om->get(\Magento\Framework\App\DeploymentConfig::class)->get('crypt/key');
        $config = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $config->method('get')->willReturn(str_repeat('o', 32) . "\n" . $real);
        $encryptor = $this->om->get(\Magento\Framework\Encryption\EncryptorInterface::class);
        $installed = count(preg_split('/\s+/', trim($real)));
        $this->assertCount($installed + 1, (new \CommerceKings\Notification\Model\KeyedHash($encryptor, $config))->all('x'),
            'computing the newest key like the Encryptor is confirmed: every listed key is used');
        $this->assertCount($installed, $this->om->get(\CommerceKings\Notification\Model\KeyedHash::class)->all('x'),
            'the install\'s own keys (one on a fresh install)');
    }

    private function hashOf(string $email): string
    {
        return (fn () => $this->hashes(Channel::EMAIL, $email)[0])->call($this->stopList);
    }
}
