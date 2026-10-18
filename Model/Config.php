<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/** Admin: Stores > Configuration > CommerceKings > Notifications. */
class Config
{
    private const PREFIX = 'commercekings_notification/general/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /** 0 = off. */
    public function getCooldownDays(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::PREFIX . 'cooldown_days'));
    }

    /** The provider selected for the store's website; null when none is stored. */
    public function getProviderCode(int $storeId, string $channel): ?string
    {
        $code = $this->scopeConfig->getValue(self::PREFIX . 'provider_' . $channel, ScopeInterface::SCOPE_STORE, $storeId);
        return is_string($code) && $code !== '' ? $code : null;
    }

    /** The "stop these emails" link in marketing notifications of this store. */
    public function isStopLinkEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag('commercekings_notification/do_not_contact/link', ScopeInterface::SCOPE_STORE,
            $storeId);
    }

    /** Days without a sign-up attempt after which a stop lifts; at least 1. */
    public function getStopLiftDays(): int
    {
        return max(1, (int)$this->scopeConfig->getValue('commercekings_notification/do_not_contact/lift_days'));
    }

    /** RFC 8058 List-Unsubscribe headers on emails of this store; the type's unsubscribe link must accept them. */
    public function isOneClickUnsubscribeEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'one_click_unsubscribe', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** The checkbox wording for address consent on a channel in this store view; '' when none is set. */
    public function getAddressConsentWording(int $storeId, string $channel): string
    {
        return trim((string)$this->scopeConfig->getValue('commercekings_notification/address_consent/wording_' . $channel,
            ScopeInterface::SCOPE_STORE, $storeId));
    }

    /** Days the evidence of a withdrawn address consent is kept; at least 1. */
    public function getAddressConsentKeepDays(int $storeId): int
    {
        return max(1, (int)$this->scopeConfig->getValue('commercekings_notification/address_consent/keep_withdrawn_days',
            ScopeInterface::SCOPE_STORE, $storeId));
    }
}
