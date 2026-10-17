<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api\Data;

use CommerceKings\Notification\Api\Channel;

/** One notification for SenderInterface::send(). */
class Delivery
{
    /**
     * @param string $templateId the email template; other channels use DeliveryContent::$text
     * @param string $sender Magento sender identity (e.g. "general"), resolved for the store
     * @param string $recipient the address on the channel: an email address for Channel::EMAIL
     * @param \Closure(): DeliveryContent $content built inside the store's environment (translations, URLs)
     * @param bool $useCooldown false for notifications the recipient asked for (e.g. back in stock)
     * @param (\Closure(): bool)|null $isRecipientCurrent re-checked after the cooldown claim, before sending
     */
    public function __construct(
        public readonly int $storeId,
        public readonly string $templateId,
        public readonly string $sender,
        public readonly string $recipient,
        public readonly \Closure $content,
        public readonly bool $useCooldown = true,
        public readonly ?\Closure $isRecipientCurrent = null,
        public readonly string $channel = Channel::EMAIL
    ) {
    }
}
