<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\OneClickHeaders;
use CommerceKings\Notification\Test\Unit\Fixture\LegacyEmailMessage;
use CommerceKings\Notification\Test\Unit\Fixture\LegacyTransport;
use CommerceKings\Notification\Test\Unit\Fixture\RecordingSender;
use Magento\Framework\Mail\TransportInterface;
use PHPUnit\Framework\TestCase;

/** The Magento 2.4.4-2.4.7 polyfill; the Symfony path (2.4.8+) is covered by MailerTest. */
class OneClickHeadersTest extends TestCase
{
    private const URL = 'https://shop.test/ckconsent/optout?c=id&t=sig';

    public static function setUpBeforeClass(): void
    {
        // Magento 2.4.8+ no longer ships laminas/laminas-mail: fall back to the test doubles
        spl_autoload_register(static function (string $class): void {
            if (str_starts_with($class, 'Laminas\\Mail\\')) {
                $file = dirname(__DIR__) . '/Fixture/Laminas/' . str_replace('\\', '/', substr($class, 13)) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });
    }

    public static function legacyTransports(): array
    {
        return [
            'Magento 2.4.4-2.4.5: sender created in the constructor' => [false, false],
            'Magento 2.4.6-2.4.7: sender created by getTransport()' => [true, false],
            'generated Interceptor: the private property lives on the parent' => [true, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyTransports')]
    public function testLegacyMagentoSendsTheHeadersAsPlainAscii(bool $lazy, bool $interceptor): void
    {
        $message = new LegacyEmailMessage();
        $message->laminas()->getHeaders()->addHeaderLine('Subject', 'Košarica');
        $message->laminas()->getHeaders()->addHeaderLine('List-Unsubscribe', '<https://old.test>');
        $sender = new RecordingSender();
        $transport = $interceptor
            ? new class ($message, $sender, $lazy) extends LegacyTransport {
            }
            : new LegacyTransport($message, $sender, $lazy);

        $this->assertTrue((new OneClickHeaders())->add($transport, self::URL));
        $transport->sendMessage();

        $this->assertCount(1, $sender->sent);
        $this->assertStringContainsString("List-Unsubscribe: <" . self::URL . ">\r\n", $sender->sent[0]);
        $this->assertStringContainsString("List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n", $sender->sent[0]);
        $this->assertStringNotContainsString('old.test', $sender->sent[0], 'replaced, not added twice');
        $this->assertStringContainsString('Subject: =?UTF-8?Q?', $sender->sent[0], 'other headers keep their encoding');
    }

    public function testTheWrappedSenderForwardsEverythingElse(): void
    {
        $sender = new RecordingSender();
        $transport = new LegacyTransport(new LegacyEmailMessage(), $sender, true);
        (new OneClickHeaders())->add($transport, self::URL);
        $this->assertSame('options x', $transport->getTransport()->setOptions('x'));
    }

    public function testAnUnknownTransportGetsNoHeaders(): void
    {
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('getMessage')->willReturn(new \stdClass());
        $this->assertFalse((new OneClickHeaders())->add($transport, self::URL));
    }

    public function testALegacyMessageWithAReplacedSenderGetsNoHeaders(): void
    {
        $message = new LegacyEmailMessage();
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('getMessage')->willReturn($message);
        $this->assertFalse((new OneClickHeaders())->add($transport, self::URL));
        $this->assertFalse($message->laminas()->getHeaders()->get('List-Unsubscribe'), 'nothing half-added');
    }
}
