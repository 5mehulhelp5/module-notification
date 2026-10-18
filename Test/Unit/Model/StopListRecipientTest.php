<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Model\AddressConsentRepository;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\KeyedHash;
use CommerceKings\Notification\Model\PhoneNormalizer;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use CommerceKings\Notification\Model\StopList;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

/** A recipient the channel cannot take (e.g. a number without "+") is refused, never stored or looked up as ''. */
class StopListRecipientTest extends TestCase
{
    private array $writes = [];

    private function stopList(): StopList
    {
        $db = $this->createStub(AdapterInterface::class);
        foreach (['insertOnDuplicate', 'update', 'delete', 'query', 'beginTransaction'] as $method) {
            $db->method($method)->willReturnCallback(function () use ($method) {
                $this->writes[] = $method;
                return 0;
            });
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $normalizers = new RecipientNormalizerPool([Channel::SMS => new PhoneNormalizer()]);
        return new StopList($resource, $this->createStub(EncryptorInterface::class), $this->createStub(UrlInterface::class),
            $this->createStub(Config::class), $normalizers, $this->createStub(SubscriptionPermissionInterface::class),
            $this->createStub(KeyedHash::class), new SubscriptionTypePool([]), new ContactRepository($resource, $normalizers),
            $this->createStub(AddressConsentRepository::class));
    }

    public function testEveryMethodRefusesARecipientTheChannelCannotTake(): void
    {
        $stopList = $this->stopList();
        $calls = [
            'stop' => fn () => $stopList->stop(Channel::SMS, '381601234567', StopList::REASON_REPLY),
            'resume' => fn () => $stopList->resume(Channel::SMS, '381601234567'),
            'touch' => fn () => $stopList->touch(Channel::SMS, '381601234567'),
            'isStopped' => fn () => $stopList->isStopped(Channel::SMS, '381601234567'),
            'getReason' => fn () => $stopList->getReason(Channel::SMS, '381601234567'),
            'getStopUrl' => fn () => $stopList->getStopUrl(Channel::SMS, '381601234567', 1),
            'empty email' => fn () => $stopList->stop(Channel::EMAIL, '   '),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail($name . ': expected an InvalidArgumentException');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringNotContainsString('381601234567', $error->getMessage(), $name . ': no number echoed');
            }
        }
        $this->assertSame([], $this->writes, 'nothing stored, nothing begun');
    }
}
