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

    /** RFC 8058 List-Unsubscribe headers on emails of this store; the type's unsubscribe link must accept them. */
    public function isOneClickUnsubscribeEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'one_click_unsubscribe', ScopeInterface::SCOPE_STORE, $storeId);
    }
}
