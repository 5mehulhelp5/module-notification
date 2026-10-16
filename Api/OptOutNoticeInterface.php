<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * The short notice a feature shows where a shopper subscribes (e.g. under the checkout email field). Each feature
 * keeps two settings in a config group of its own: `show` (Automatic / Yes / No, see
 * Model\Config\Source\NoticeMode) and `text`. Automatic shows it in opt-out mode and hides it when a permission
 * module (e.g. the consent bridge) requires a recorded permission.
 *
 * @api
 */
interface OptOutNoticeInterface
{
    /**
     * The notice to show in this store, or null when it is off or empty.
     *
     * @param string $configGroup the feature's group path, e.g. "commercekings_abandoned_cart/notice"
     */
    public function getText(string $configGroup, int $storeId): ?string;
}
