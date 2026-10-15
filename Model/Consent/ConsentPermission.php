<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model\Consent;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A subscription needs a valid CommerceKings_Consent consent for the type's purpose on the target's website;
 * the consent id is the permission reference. The visitor is identified by the browser the consent was given in.
 */
class ConsentPermission implements SubscriptionPermissionInterface
{
    public function __construct(
        private readonly ConsentInterface $consent,
        private readonly ConsentGuardInterface $guard,
        private readonly StoreManagerInterface $stores,
        private readonly BrowserIdentityInterface $browser,
        private readonly ConsentStore $records
    ) {
    }

    public function check(SubscriptionTypeInterface $type, Target $target): Permission
    {
        $consent = $this->consent->getCurrent($type->getPurpose());
        if ($consent === null) {
            return Permission::absent();
        }
        if ($consent->purpose !== $type->getPurpose()
            || $consent->websiteId !== (int)$this->stores->getStore($target->storeId)->getWebsiteId()
        ) {
            return Permission::refused();
        }
        return Permission::granted($consent->consentId);
    }

    public function validCondition(string $referenceColumn): string
    {
        return $this->guard->validCondition($referenceColumn);
    }

    public function identifiesVisitor(): bool
    {
        return true;
    }

    public function isCurrentVisitor(string $reference): bool
    {
        $consentBrowser = (string)($this->records->load($reference)['browser_hash'] ?? '');
        $browser = $this->browser->hash();
        return $consentBrowser !== '' && $browser !== '' && hash_equals($consentBrowser, $browser);
    }
}
