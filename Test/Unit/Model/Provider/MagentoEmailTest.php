<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model\Provider;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\Notification\Model\OneClickHeaders;
use CommerceKings\Notification\Model\Provider\MagentoEmail;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

require_once __DIR__ . '/../../Fixture/LaminasProtocolException.php';

/** Magento's mail transport refusing: an SMTP 4xx reply is "not sent, try again soon", anything else as it is. */
class MagentoEmailTest extends TestCase
{
    private ?\Throwable $failure = null;
    private int $sent = 0;

    private function send(): void
    {
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function () {
            if ($this->failure) {
                throw $this->failure;
            }
            $this->sent++;
        });
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturn($builder);
        }
        $builder->method('getTransport')->willReturn($transport);
        (new MagentoEmail($builder, new OneClickHeaders(), $this->createStub(\Psr\Log\LoggerInterface::class)))->send(
            new Delivery(1, 'template', 'general', 'a@test.com', fn () => new DeliveryContent([])), new DeliveryContent([]));
    }

    /** Magento's transport wraps the mailer's error: MailException("Transport error: ...") with it as previous. */
    private function wrapped(\Throwable $error): MailException
    {
        return new MailException(new Phrase('Transport error: Unable to send mail at this time.'), $error);
    }

    public function testASuccessfulHandoffSends(): void
    {
        $this->send();
        $this->assertSame(1, $this->sent);
    }

    public function testAnSmtp4xxReplyIsATemporaryRefusal(): void
    {
        foreach ([421, 450, 451, 452] as $code) {
            $this->failure = $this->wrapped(new UnexpectedResponseException(
                sprintf('Expected response code "250" but got code "%d", with message "%d 4.7.1 Try again later".', $code, $code), $code));
            try {
                $this->send();
                $this->fail('Expected a temporary refusal for ' . $code);
            } catch (TemporaryFailureException $error) {
                $this->assertStringContainsString('SMTP ' . $code, $error->getMessage());
                $this->assertSame($this->failure, $error->getPrevious(), 'the original error is kept');
                $this->assertNull($error->getRetryAfter(), 'SMTP gives no wait');
            }
        }
    }

    public function testLaminasMailsReplyInTheMessageCountsToo(): void
    {
        $this->failure = $this->wrapped(new \Laminas\Mail\Protocol\Exception\RuntimeException('451 4.3.0 Mail server temporarily rejected message'));
        $this->expectException(TemporaryFailureException::class);
        $this->send();
    }

    public function testPermanentAndOtherFailuresAreRethrownAsTheyAre(): void
    {
        foreach ([
            $this->wrapped(new UnexpectedResponseException('550 5.1.1 User unknown', 550)),
            $this->wrapped(new TransportException('Connection could not be established with host "smtp".')),
            new \RuntimeException('451 not from a mail library'),
            $this->wrapped(new \Laminas\Mail\Protocol\Exception\RuntimeException('554 5.7.1 Rejected')),
        ] as $failure) {
            $this->failure = $failure;
            try {
                $this->send();
                $this->fail('Expected the failure');
            } catch (TemporaryFailureException) {
                $this->fail('Not temporary: ' . $failure->getMessage());
            } catch (\Throwable $error) {
                $this->assertSame($failure, $error);
            }
        }
    }
}
