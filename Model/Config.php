<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

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
}
