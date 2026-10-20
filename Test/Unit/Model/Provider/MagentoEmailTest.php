<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model\Provider;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Exception\MessageSentException;
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

    /** Symfony Mailer's SMTP transcript (TransportException::getDebug()) up to the failure. */
    private static function transcript(string ...$lines): string
    {
        return implode('', array_map(fn ($line) => '[2026-10-20T10:00:00.000000] ' . $line . "\n", $lines));
    }

    public function testAConnectionLostAfterTheMessageWasHandedOverMayHaveSent(): void
    {
        foreach (['Connection to "smtp:587" has been closed unexpectedly.',
            'Connection to "smtp:587" timed out.'] as $lost) {
            $error = new TransportException($lost);
            $error->appendDebug(self::transcript('> MAIL FROM:<shop@test.com>', '< 250 OK', '> DATA', '< 354 Go ahead',
                '> ', '> .'));
            $this->failure = $this->wrapped($error);
            try {
                $this->send();
                $this->fail('Expected "may have been sent"');
            } catch (MessageSentException $sent) {
                $this->assertSame($this->failure, $sent->getPrevious());
                $this->assertStringContainsString('may have taken it', $sent->getMessage());
            }
        }
    }

    public function testALostConnectionBeforeTheEndOfTheMessageIsSafeToRetry(): void
    {
        foreach ([self::transcript('> EHLO shop', '< 250 OK'), self::transcript('> DATA', '< 354 Go ahead'),
            ''] as $debug) {
            $error = new TransportException('Connection to "smtp:587" has been closed unexpectedly.');
            $error->appendDebug($debug);
            $this->failure = $this->wrapped($error);
            try {
                $this->send();
                $this->fail('Expected the failure');
            } catch (MessageSentException) {
                $this->fail('the end of the message never went out: not taken - ' . $debug);
            } catch (\Throwable $error) {
                $this->assertSame($this->failure, $error, 'rethrown: retried');
            }
        }
    }

    public function testLaminasMailWaitingForTheServersReplyMayHaveSent(): void
    {
        foreach (['smtp.test has timed out', 'Could not read from smtp.test'] as $lost) {
            $this->failure = $this->wrapped(new \Laminas\Mail\Protocol\Exception\RuntimeException($lost));
            try {
                $this->send();
                $this->fail('Expected "may have been sent"');
            } catch (MessageSentException $sent) {
                $this->assertSame($this->failure, $sent->getPrevious(), $lost);
            }
        }
    }

    public function testPermanentAndOtherFailuresAreRethrownAsTheyAre(): void
    {
        foreach ([
            $this->wrapped(new UnexpectedResponseException('550 5.1.1 User unknown', 550)),
            $this->wrapped(new TransportException('Connection could not be established with host "smtp".')),
            new \RuntimeException('451 not from a mail library'),
            $this->wrapped(new \Laminas\Mail\Protocol\Exception\RuntimeException('554 5.7.1 Rejected')),
            $this->wrapped(new \Laminas\Mail\Protocol\Exception\RuntimeException(
                'Could not send request to smtp.test')),
            // a final reply after the message: refused, not taken
            $this->wrapped((function () {
                $refused = new UnexpectedResponseException('Expected response code "250" but got code "554"', 554);
                $refused->appendDebug(self::transcript('> DATA', '< 354 Go ahead', '> .', '< 554 Rejected'));
                return $refused;
            })()),
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
