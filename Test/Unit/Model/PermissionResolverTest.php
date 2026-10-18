<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Model\PermissionResolver;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use CommerceKings\Notification\Model\TypePermission;
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
        return new PermissionResolver($this->shopWide, new SubscriptionTypePool($types));
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
        $this->assertSame("((s.type = 'it\\'s' AND (SMS(s.ref))) OR (s.type NOT IN ('it\\'s') AND (SHOP(s.ref))))",
            $resolver->validCondition('s.ref', 's.type'), 'type codes are quoted');
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
}
