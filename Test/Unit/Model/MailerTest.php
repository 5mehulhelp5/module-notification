<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\Data\Message;
use CommerceKings\Notification\Api\Data\MessageContent;
use CommerceKings\Notification\Api\MailerInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\Mailer;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class MailerTest extends TestCase
{
    private array $mail = [];
    private int $sent = 0;
    private bool $sendFails = false;
    private bool $inCooldown = false;
    private array $claims = [];
    private array $released = [];
    private array $emulated = [];
    private \Symfony\Component\Mime\Email $native;

    private function mailer(int $cooldownDays = 7, bool $stopFails = false): Mailer
    {
        $this->native = (new \Symfony\Component\Mime\Email())->from('shop@example.test')->to('a@test.com')->text('x');
        $message = $this->createStub(EmailMessage::class);
        $message->method('getSymfonyMessage')->willReturn($this->native);
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('getMessage')->willReturn($message);
        $transport->method('sendMessage')->willReturnCallback(function () {
            if ($this->sendFails) {
                throw new \RuntimeException('SMTP down');
            }
            $this->sent++;
        });
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnCallback(function (...$args) use ($builder, $method) {
                $this->mail[$method] = $args;
                return $builder;
            });
        }
        $builder->method('getTransport')->willReturn($transport);
        $emulation = $this->createStub(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(function ($storeId) { $this->emulated[] = 'start ' . $storeId; });
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () use ($stopFails) {
            $this->emulated[] = 'stop';
            if ($stopFails) {
                throw new \RuntimeException('emulation cleanup failed');
            }
        });
        $cooldown = $this->createStub(CooldownInterface::class);
        $cooldown->method('claim')->willReturnCallback(function ($email) {
            $this->claims[] = $email;
            return $this->inCooldown ? null : 'token';
        });
        $cooldown->method('release')->willReturnCallback(function ($email, $token) { $this->released[] = [$email, $token]; });
        $config = $this->createStub(Config::class);
        $config->method('getCooldownDays')->willReturn($cooldownDays);
        return new Mailer($builder, $emulation, $cooldown, $config);
    }

    private function message(bool $useCooldown = true, ?\Closure $current = null, ?string $oneClick = null): Message
    {
        return new Message(3, 'tpl', 'general', 'a@test.com',
            fn () => new MessageContent(['customer_name' => 'Jane'], 'Jane', $oneClick), $useCooldown, $current);
    }

    public function testSendsInTheMessagesStoreWithItsTemplateSenderAndRecipient(): void
    {
        $this->assertSame(MailerInterface::SENT, $this->mailer()->send($this->message()));
        $this->assertSame(1, $this->sent);
        $this->assertSame(['tpl'], $this->mail['setTemplateIdentifier']);
        $this->assertSame([['area' => 'frontend', 'store' => 3]], $this->mail['setTemplateOptions']);
        $this->assertSame(['general', 3], $this->mail['setFromByScope']);
        $this->assertSame(['a@test.com', 'Jane'], $this->mail['addTo']);
        $this->assertSame(['start 3', 'stop'], $this->emulated, 'content is built inside the store');
        $this->assertSame(['a@test.com'], $this->claims);
        $this->assertSame([], $this->released);
    }

    public function testInCooldownNothingIsSent(): void
    {
        $this->inCooldown = true;
        $this->assertSame(MailerInterface::COOLDOWN, $this->mailer()->send($this->message()));
        $this->assertSame(0, $this->sent);
        $this->assertSame([], $this->emulated);
    }

    public function testCooldownIsOptionalPerMessageAndPerShop(): void
    {
        $this->inCooldown = true;
        $this->assertSame(MailerInterface::SENT, $this->mailer()->send($this->message(useCooldown: false)));
        $this->assertSame(MailerInterface::SENT, $this->mailer(cooldownDays: 0)->send($this->message()));
        $this->assertSame(2, $this->sent);
        $this->assertSame([], $this->claims, 'requested emails and a shop without cooldown never claim');
    }

    public function testRecipientChangedAfterTheClaimReleasesItAndSendsNothing(): void
    {
        $this->assertSame(MailerInterface::RECIPIENT_CHANGED, $this->mailer()->send($this->message(current: fn () => false)));
        $this->assertSame(0, $this->sent);
        $this->assertSame([['a@test.com', 'token']], $this->released);
    }

    public function testFailureBeforeHandoffReleasesTheClaimAndRethrows(): void
    {
        $this->sendFails = true;
        try {
            $this->mailer()->send($this->message());
            $this->fail('Expected SMTP failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('SMTP down', $error->getMessage());
        }
        $this->assertSame([['a@test.com', 'token']], $this->released);
        $this->assertSame(['start 3', 'stop'], $this->emulated);
    }

    public function testRecipientCheckFailureReleasesTheClaimBeforeRenderingOrSending(): void
    {
        $error = new \RuntimeException('Recipient lookup unavailable');
        $mailer = $this->mailer();
        try {
            $mailer->send($this->message(current: static function () use ($error): bool { throw $error; }));
            $this->fail('Recipient lookup failure must stop delivery.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
        $this->assertSame([['a@test.com', 'token']], $this->released);
        $this->assertSame(0, $this->sent);
        $this->assertSame([], $this->mail);
        $this->assertSame([], $this->emulated);
        $this->assertSame(MailerInterface::SENT, $mailer->send($this->message()));
        $this->assertSame(1, $this->sent);
    }

    public function testFailureAfterHandoffKeepsTheClaimAndSaysSent(): void
    {
        try {
            $this->mailer(stopFails: true)->send($this->message());
            $this->fail('Expected cleanup failure');
        } catch (MessageSentException $error) {
            $this->assertSame('emulation cleanup failed', $error->getPrevious()?->getMessage());
        }
        $this->assertSame(1, $this->sent);
        $this->assertSame([], $this->released, 'the email is out: never allow a second one');
    }

    public function testOneClickHeadersOnlyForHttpsUrls(): void
    {
        $this->mailer()->send($this->message(oneClick: 'https://shop.test/ckconsent/optout?c=id&t=sig'));
        $headers = $this->native->getHeaders();
        $this->assertSame('<https://shop.test/ckconsent/optout?c=id&t=sig>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());

        try {
            $this->mailer()->send($this->message(oneClick: 'http://shop.test/optout'));
            $this->fail('Expected insecure URL refusal');
        } catch (\UnexpectedValueException $error) {
            $this->assertSame('One-click unsubscribe requires an HTTPS opt-out URL.', $error->getMessage());
        }
        $this->assertSame(1, $this->sent);
    }
}
