<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Model\PermissionResolver;
use CommerceKings\Notification\Model\SubscriberResolver;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use CommerceKings\Notification\Model\TypePermission;
use CommerceKings\Notification\Model\TypeSubscriber;
use PHPUnit\Framework\TestCase;

/** Each type is checked by its own permission when it has one, else by the shop-wide one. */
class PermissionResolverTest extends TestCase
{
    private SubscriptionPermissionInterface $shopWide;
    private SubscriptionPermissionInterface $sms;

    protected function setUp(): void
    {
        $this->shopWide = $this->permission('SHOP', false);
        $this->sms = $this->permission('SMS', true);
    }

    private function permission(string $name, bool $optOut): SubscriptionPermissionInterface
    {
        $permission = $this->createStub(SubscriptionPermissionInterface::class);
        $permission->method('check')->willReturn(Permission::granted(strtolower($name)));
        $permission->method('validCondition')->willReturnCallback(fn ($column) => $name . '(' . $column . ')');
        $permission->method('isOptOut')->willReturn($optOut);
        return $permission;
    }

    private function type(?SubscriptionPermissionInterface $own): SubscriptionTypeInterface
    {
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('getPermission')->willReturn($own);
        return $type;
    }

    private function resolver(array $types): PermissionResolver
    {
        $db = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $quote = fn ($value) => "'" . str_replace("'", "''", (string)$value) . "'";
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) => str_replace('?',
            is_array($value) ? implode(', ', array_map($quote, $value)) : $quote($value), $text));
        $resource = $this->createStub(\Magento\Framework\App\ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        return new PermissionResolver($this->shopWide, new SubscriptionTypePool($types), $resource);
    }

    public function testATypeUsesItsOwnPermissionElseTheShopWideOne(): void
    {
        $resolver = $this->resolver(['cart' => $this->type(null), 'sms_offer' => $this->type($this->sms)]);
        $this->assertSame($this->shopWide, $resolver->forCode('cart'));
        $this->assertSame($this->sms, $resolver->forCode('sms_offer'));
        $this->assertSame($this->shopWide, $resolver->forCode('uninstalled'), 'an unknown type: shop-wide');
    }

    public function testWithoutOwnPermissionsTheConditionIsTheShopWideOne(): void
    {
        $this->assertSame('SHOP(s.ref)', $this->resolver(['cart' => $this->type(null)])->validCondition('s.ref', 's.type'));
    }

    public function testTheConditionChecksEachRowByItsTypesPermission(): void
    {
        $resolver = $this->resolver(['cart' => $this->type(null), "it's" => $this->type($this->sms)]);
        $this->assertSame("((s.type = 'it''s' AND (SMS(s.ref))) OR (s.type NOT IN ('it''s') AND (SHOP(s.ref))))",
            $resolver->validCondition('s.ref', 's.type'), 'type codes are quoted by the database adapter');
        $this->assertSame(["it's"], $resolver->ownPermissionTypes());
    }

    public function testTypePermissionActsAsTheTypesPermission(): void
    {
        $resolver = $this->resolver(['sms_offer' => $this->type($this->sms)]);
        $permission = new TypePermission($resolver, 'sms_offer');
        $this->assertSame('SMS(ref)', $permission->validCondition('ref'));
        $this->assertTrue($permission->isOptOut());
        $this->assertSame('sms', $permission->check($this->type($this->sms), new Target(1, 1, true, '1 = 1'))->reference);
        $this->assertFalse((new TypePermission($resolver, 'cart'))->isOptOut(), 'no own permission: shop-wide');
    }

    /** The subscriber follows the type like the permission: its own, else the shop-wide one. */
    public function testATypeUsesItsOwnSubscriberElseTheShopWideOne(): void
    {
        $shopWide = $this->createStub(SubscriberInterface::class);
        $shopWide->method('isCurrentVisitor')->willReturn(false);
        $own = $this->createStub(SubscriberInterface::class);
        $own->method('isCurrentVisitor')->willReturn(true);
        $own->method('getUnsubscribeUrl')->willReturn('https://shop.test/sms/stop');
        $sms = $this->createStub(SubscriptionTypeInterface::class);
        $sms->method('getSubscriber')->willReturn($own);
        $resolver = new SubscriberResolver($shopWide, new SubscriptionTypePool(['cart' => $this->type(null), 'sms_offer' => $sms]));
        $this->assertSame($shopWide, $resolver->forCode('cart'));
        $this->assertSame($own, $resolver->forCode('sms_offer'));
        $this->assertSame($shopWide, $resolver->forCode('uninstalled'));
        $subscriber = new TypeSubscriber($resolver, 'sms_offer');
        $this->assertTrue($subscriber->isCurrentVisitor('sms-checkbox'));
        $this->assertSame('https://shop.test/sms/stop', $subscriber->getUnsubscribeUrl(
            new \CommerceKings\Notification\Api\Data\Subscription(1, 'sms_offer', 7, 1, 3, null, 'sms-checkbox', '2026-10-18 10:00:00')));
        $this->assertFalse((new TypeSubscriber($resolver, 'cart'))->isCurrentVisitor('consent-id'));
    }

    /** TypePermission hands the recipient on: a feature's claim checks the type's own permission for that address. */
    public function testTypePermissionPassesTheRecipientOn(): void
    {
        $seen = [];
        $own = $this->createStub(SubscriptionPermissionInterface::class);
        $own->method('check')->willReturnCallback(function ($type, $target, $recipient = null) use (&$seen) {
            $seen[] = $recipient;
            return Permission::granted('own');
        });
        $type = $this->type($own);
        (new TypePermission($this->resolver(['sms_offer' => $type]), 'sms_offer'))
            ->check($type, new Target(1, 1, true, '1 = 1'), '+381601234567');
        $this->assertSame(['+381601234567'], $seen);
    }
}
