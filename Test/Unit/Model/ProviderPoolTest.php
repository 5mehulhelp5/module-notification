<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\ProviderPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Which provider sends a channel: the admin's choice, the channel's default, else the first installed one. */
class ProviderPoolTest extends TestCase
{
    private array $warnings = [];
    private ?string $selected = null;

    private function provider(string $code, string $channel): ProviderInterface
    {
        $provider = $this->createStub(ProviderInterface::class);
        $provider->method('getCode')->willReturn($code);
        $provider->method('getChannel')->willReturn($channel);
        return $provider;
    }

    private function pool(array $providers): ProviderPool
    {
        $config = $this->createStub(Config::class);
        $config->method('getProviderCode')->willReturnCallback(fn () => $this->selected);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) { $this->warnings[] = $message; });
        return new ProviderPool($config, $providers, $logger);
    }

    /** Several installed, none selected: module order decides, which a deployment can change - say so once. */
    public function testAnAmbiguousFallbackIsLoggedOncePerChannel(): void
    {
        $pool = $this->pool([$this->provider('infobip_sms', Channel::SMS), $this->provider('twilio_sms', Channel::SMS)]);
        $this->assertSame('infobip_sms', $pool->getSelected(1, Channel::SMS)->getCode());
        $pool->getSelected(2, Channel::SMS);
        $this->assertCount(1, $this->warnings, 'once per channel and process, not per delivery');
        $this->assertStringContainsString('"sms"', $this->warnings[0]);
        $this->assertStringContainsString('"infobip_sms"', $this->warnings[0]);
    }

    public function testAChoiceADefaultOrASingleProviderIsNotLogged(): void
    {
        $this->pool([$this->provider('twilio_sms', Channel::SMS)])->getSelected(1, Channel::SMS);
        $this->pool([$this->provider('magento_email', Channel::EMAIL), $this->provider('acme_mail', Channel::EMAIL)])
            ->getSelected(1, Channel::EMAIL);
        $this->selected = 'twilio_sms';
        $chosen = $this->pool([$this->provider('infobip_sms', Channel::SMS), $this->provider('twilio_sms', Channel::SMS)])
            ->getSelected(1, Channel::SMS);
        $this->assertSame('twilio_sms', $chosen->getCode());
        $this->assertSame([], $this->warnings);
    }

    public function testNoProviderForTheChannelIsAnError(): void
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->pool([$this->provider('magento_email', Channel::EMAIL)])->getSelected(1, Channel::WHATSAPP);
    }
}
