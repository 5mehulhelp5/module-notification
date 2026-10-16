<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\OptOutNoticeInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Model\Config\Source\NoticeMode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class OptOutNotice implements OptOutNoticeInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SubscriptionPermissionInterface $permission
    ) {
    }

    public function getText(string $configGroup, int $storeId): ?string
    {
        $show = (string)$this->scopeConfig->getValue($configGroup . '/show', ScopeInterface::SCOPE_STORE, $storeId);
        $shown = match ($show) {
            NoticeMode::YES => true,
            NoticeMode::NO => false,
            default => $this->permission->isOptOut(),
        };
        if (!$shown) {
            return null;
        }
        $text = trim((string)$this->scopeConfig->getValue($configGroup . '/text', ScopeInterface::SCOPE_STORE, $storeId));
        return $text === '' ? null : $text;
    }
}
