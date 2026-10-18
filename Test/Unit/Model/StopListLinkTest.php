<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\StopList;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

/** The "Stop these emails" link: the admin may turn it off, except in opt-out mode, where it is the only way out. */
class StopListLinkTest extends TestCase
{
    private function url(bool $linkSetting, bool $optOut): string
    {
        $config = $this->createStub(Config::class);
        $config->method('isStopLinkEnabled')->willReturn($linkSetting);
        $permission = $this->createStub(SubscriptionPermissionInterface::class);
        $permission->method('isOptOut')->willReturn($optOut);
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturn('0:3:cipher');
        $encryptor->method('hash')->willReturn(str_repeat('s', 64));
        $hashes = $this->createStub(\CommerceKings\Notification\Model\KeyedHash::class);
        $hashes->method('current')->willReturn(str_repeat('s', 64));
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(fn ($route, $params) => 'https://shop.test/' . $route . '?'
            . http_build_query($params['_query']));
        return (new StopList($this->createStub(ResourceConnection::class), $encryptor, $url, $config,
            new RecipientNormalizerPool(), $permission, $hashes,
            new \CommerceKings\Notification\Model\SubscriptionTypePool([]),
            new \CommerceKings\Notification\Model\ContactRepository($this->createStub(ResourceConnection::class),
                new RecipientNormalizerPool())))
            ->getStopUrl(Channel::EMAIL, 'a@example.test', 1);
    }

    public function testTheSettingDecidesWithConsent(): void
    {
        $this->assertSame('', $this->url(false, false), 'opt-in: the consent withdrawal link is the way out');
        $this->assertStringContainsString('cknotification/stop', $this->url(true, false));
    }

    public function testOptOutModeAlwaysOffersTheLink(): void
    {
        $this->assertStringContainsString('cknotification/stop', $this->url(false, true), 'setting off, still offered');
        $this->assertStringContainsString('cknotification/stop', $this->url(true, true));
    }
}
