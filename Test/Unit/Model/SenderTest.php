<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\Config\Source\Provider as ProviderSource;
use CommerceKings\Notification\Model\OneClickHeaders;
use CommerceKings\Notification\Model\Provider\MagentoEmail;
use CommerceKings\Notification\Model\ProviderPool;
use CommerceKings\Notification\Model\Sender;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
/** The sender with the real ProviderPool and MagentoEmail provider; Magento's transport is stubbed. */
class SenderTest extends TestCase
{
    private array $mail = [];
    private int $sent = 0;
    private bool $sendFails = false;
    private bool $inCooldown = false;
    private array $claims = [];
    private array $released = [];
    private array $emulated = [];
    private array $warnings = [];
    private \Symfony\Component\Mime\Email $native;
    private ProviderPool $pool;

    /** @param object|null $mailMessage the transport's message; null: Magento 2.4.8+ (Symfony) */
    /** @param ProviderInterface[] $extra more providers; $selected the stored provider code */
    private function sender(int $cooldownDays = 7, bool $stopFails = false, ?object $mailMessage = null,
        array $extra = [], ?string $selected = null): Sender
    {
        $this->native = (new \Symfony\Component\Mime\Email())->from('shop@example.test')->to('a@test.com')->text('x');
        $message = $mailMessage ?? $this->createStub(EmailMessage::class);
        if ($mailMessage === null) {
            $message->method('getSymfonyMessage')->willReturn($this->native);
        }
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
        $config->method('getProviderCode')->willReturn($selected);
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($text) { $this->warnings[] = $text; });
        $this->pool = new ProviderPool($config, [new MagentoEmail($builder, new OneClickHeaders(), $logger), ...$extra]);
        return new Sender($this->pool, $emulation, $cooldown, $config, $logger);
    }

    private function message(bool $useCooldown = true, ?\Closure $current = null, ?string $oneClick = null,
        string $channel = Channel::EMAIL, string $recipient = 'a@test.com'): Delivery
    {
        return new Delivery(3, 'tpl', 'general', $recipient,
            fn () => new DeliveryContent(['customer_name' => 'Jane'], 'Jane', $oneClick, 'Your cart is waiting'),
            $useCooldown, $current, $channel);
    }

    public function testSendsInTheMessagesStoreWithItsTemplateSenderAndRecipient(): void
    {
        $this->assertSame(SenderInterface::SENT, $this->sender()->send($this->message()));
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
        $this->assertSame(SenderInterface::COOLDOWN, $this->sender()->send($this->message()));
        $this->assertSame(0, $this->sent);
        $this->assertSame([], $this->emulated);
    }

    public function testCooldownIsOptionalPerMessageAndPerShop(): void
    {
        $this->inCooldown = true;
        $this->assertSame(SenderInterface::SENT, $this->sender()->send($this->message(useCooldown: false)));
        $this->assertSame(SenderInterface::SENT, $this->sender(cooldownDays: 0)->send($this->message()));
        $this->assertSame(2, $this->sent);
        $this->assertSame([], $this->claims, 'requested emails and a shop without cooldown never claim');
    }

    public function testRecipientChangedAfterTheClaimReleasesItAndSendsNothing(): void
    {
        $this->assertSame(SenderInterface::RECIPIENT_CHANGED, $this->sender()->send($this->message(current: fn () => false)));
        $this->assertSame(0, $this->sent);
        $this->assertSame([['a@test.com', 'token']], $this->released);
    }

    public function testFailureBeforeHandoffReleasesTheClaimAndRethrows(): void
    {
        $this->sendFails = true;
        try {
            $this->sender()->send($this->message());
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
        $mailer = $this->sender();
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
        $this->assertSame(SenderInterface::SENT, $mailer->send($this->message()));
        $this->assertSame(1, $this->sent);
    }

    public function testFailureAfterHandoffKeepsTheClaimAndSaysSent(): void
    {
        try {
            $this->sender(stopFails: true)->send($this->message());
            $this->fail('Expected cleanup failure');
        } catch (MessageSentException $error) {
            $this->assertSame('emulation cleanup failed', $error->getPrevious()?->getMessage());
        }
        $this->assertSame(1, $this->sent);
        $this->assertSame([], $this->released, 'the email is out: never allow a second one');
    }

    public function testOneClickHeadersOnlyForHttpsUrls(): void
    {
        $this->sender()->send($this->message(oneClick: 'https://shop.test/ckconsent/optout?c=id&t=sig'));
        $headers = $this->native->getHeaders();
        $this->assertSame('<https://shop.test/ckconsent/optout?c=id&t=sig>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());

        // an http store (e.g. staging): the email still goes out, without the headers
        $this->assertSame(SenderInterface::SENT, $this->sender()->send($this->message(oneClick: 'http://shop.test/optout')));
        $this->assertNull($this->native->getHeaders()->get('List-Unsubscribe'));
        $this->assertStringContainsString('needs an HTTPS store URL', implode(' ', $this->warnings));
        $this->assertSame(2, $this->sent);
    }

    public function testUnknownMailMessageSendsWithoutOneClickHeadersAndWarns(): void
    {
        $this->assertSame(SenderInterface::SENT, $this->sender(mailMessage: new \stdClass())
            ->send($this->message(oneClick: 'https://shop.test/optout')));
        $this->assertSame(1, $this->sent, 'a missing header never blocks the email');
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('without them', $this->warnings[0]);
    }

    /** A provider for another channel that records what it was given. */
    private function textProvider(?\Throwable $error = null): ProviderInterface
    {
        $provider = $this->createStub(ProviderInterface::class);
        $provider->method('getCode')->willReturn('test_text');
        $provider->method('getLabel')->willReturn('Test text');
        $provider->method('getChannel')->willReturn('text');
        $provider->method('supportsOneClickUnsubscribe')->willReturn(false);
        $provider->method('send')->willReturnCallback(function (Delivery $delivery, DeliveryContent $content) use ($error) {
            if ($error) {
                throw $error;
            }
            $this->mail['text'] = [$delivery->recipient, $content->text];
            $this->mail['text_one_click'] = $content->oneClickUnsubscribeUrl;
        });
        return $provider;
    }

    public function testEachChannelUsesItsOwnProviderAndCooldownKey(): void
    {
        $sender = $this->sender(extra: [$this->textProvider()]);
        $text = Delivery::text(3, 'text', '+381601234567', fn () => new DeliveryContent([], text: 'Your cart is waiting'));
        $this->assertSame(['', ''], [$text->templateId, $text->sender], 'a text delivery needs no template or sender');
        $this->assertSame(SenderInterface::SENT, $sender->send($text));
        $this->assertSame(['+381601234567', 'Your cart is waiting'], $this->mail['text']);
        $this->assertSame(0, $this->sent, 'the email provider is not involved');
        $this->assertSame(SenderInterface::SENT, $sender->send($this->message()));
        $this->assertSame(['text:+381601234567', 'a@test.com'], $this->claims, 'email keeps the plain address as key');
    }

    public function testOneClickUrlOnlyReachesProvidersThatSupportIt(): void
    {
        $sender = $this->sender(extra: [$this->textProvider()]);
        $text = Delivery::text(3, 'text', '+381601234567',
            fn () => new DeliveryContent([], null, 'https://shop.test/optout', 'Your cart is waiting'));
        $this->assertSame(SenderInterface::SENT, $sender->send($text));
        $this->assertNull($this->mail['text_one_click'], 'a provider without the capability never gets the URL');
        $this->assertStringContainsString('provider "test_text" cannot add its headers', implode(' ', $this->warnings));

        $this->warnings = [];
        $this->assertSame(SenderInterface::SENT, $sender->send($this->message(oneClick: 'https://shop.test/optout')));
        $this->assertNotNull($this->native->getHeaders()->get('List-Unsubscribe'), 'Magento email supports it');
        $this->assertSame([], $this->warnings);
    }

    public function testProviderReportingSentKeepsTheClaim(): void
    {
        $sent = new MessageSentException('accepted, then the API timed out');
        try {
            $this->sender(extra: [$this->textProvider($sent)])->send($this->message(channel: 'text', recipient: '+1'));
            $this->fail('Expected MessageSentException');
        } catch (MessageSentException $error) {
            $this->assertSame($sent, $error);
        }
        $this->assertSame([], $this->released, 'accepted by the provider: never allow a second one');
    }

    public function testSelectedProviderFallsBackToTheChannelDefaultAndNeverCrossesChannels(): void
    {
        $this->sender(extra: [$this->textProvider()], selected: 'test_text');
        $this->assertSame('magento_email', $this->pool->getSelected(3, Channel::EMAIL)->getCode(), 'a text provider is never used for email');
        $this->sender(selected: 'removed_module');
        $this->assertSame('magento_email', $this->pool->getSelected(3, Channel::EMAIL)->getCode());
        $this->expectException(LocalizedException::class);
        $this->pool->getSelected(3, 'text');
    }

    public function testNoProviderForTheChannelReleasesTheClaim(): void
    {
        try {
            $this->sender()->send($this->message(channel: 'text', recipient: '+1'));
            $this->fail('Expected a missing provider');
        } catch (LocalizedException) {
            $this->assertSame([['text:+1', 'token']], $this->released);
            $this->assertSame([], $this->emulated);
        }
    }

    public function testAdminListsOnlyTheChannelsProviders(): void
    {
        $this->sender(extra: [$this->textProvider()]);
        $this->assertSame([['value' => 'magento_email', 'label' => 'Magento email']], (new ProviderSource($this->pool))->toOptionArray());
        $this->assertSame([['value' => 'test_text', 'label' => 'Test text']], (new ProviderSource($this->pool, 'text'))->toOptionArray());
    }
}
