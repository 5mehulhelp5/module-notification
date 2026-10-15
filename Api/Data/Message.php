<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api\Data;

/** One email for MailerInterface::send(). */
final class Message
{
    /**
     * @param string $sender Magento sender identity (e.g. "general"), resolved for the store
     * @param \Closure(): MessageContent $content built inside the store's environment (translations, URLs)
     * @param bool $useCooldown false for emails the recipient asked for (e.g. back in stock)
     * @param (\Closure(): bool)|null $isRecipientCurrent re-checked after the cooldown claim, before sending
     */
    public function __construct(
        public readonly int $storeId,
        public readonly string $templateId,
        public readonly string $sender,
        public readonly string $email,
        public readonly \Closure $content,
        public readonly bool $useCooldown = true,
        public readonly ?\Closure $isRecipientCurrent = null
    ) {
    }
}
