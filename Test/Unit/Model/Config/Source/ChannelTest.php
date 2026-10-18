<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model\Config\Source;

use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Model\Config\Source\Channel;
use PHPUnit\Framework\TestCase;

/** The channels to choose from (e.g. quiet hours): email first, then each installed provider's channel once. */
class ChannelTest extends TestCase
{
    public function testCoreChannelsByNameOthersByCodeEachOnce(): void
    {
        $providers = [];
        foreach (['magento_email' => 'email', 'twilio_sms' => 'sms', 'infobip_sms' => 'sms', 'twilio_whatsapp' => 'whatsapp',
            'acme_viber' => 'viber', 'acme_push' => 'push'] as $code => $channel) {
            $provider = $this->createStub(ProviderInterface::class);
            $provider->method('getChannel')->willReturn($channel);
            $providers[$code] = $provider;
        }
        $pool = $this->createStub(ProviderPoolInterface::class);
        $pool->method('getAll')->willReturn($providers);
        $options = array_map(fn ($option) => [$option['value'], (string)$option['label']], (new Channel($pool))->toOptionArray());
        $this->assertSame([['email', 'Email'], ['sms', 'SMS'], ['whatsapp', 'WhatsApp'], ['viber', 'Viber'], ['push', 'push']],
            $options);
    }
}
