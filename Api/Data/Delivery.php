<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api\Data;

use CommerceKings\Notification\Api\Channel;

/** One notification for SenderInterface::send(). */
class Delivery
{
    /**
     * @param string $templateId the email template; '' for text channels (see text())
     * @param string $sender Magento sender identity (e.g. "general"), resolved for the store; '' for text channels
     * @param string $recipient the address on the channel: an email address for Channel::EMAIL
     * @param \Closure(): DeliveryContent $content built inside the store's environment (translations, URLs)
     * @param bool $useCooldown false for notifications the recipient asked for (e.g. back in stock)
     * @param (\Closure(): bool)|null $isRecipientCurrent re-checked after the cooldown claim, before sending
     * @param bool $stoppable whether the recipient can stop it with Do not contact (the feature decides: e.g. a
     *     reminder yes, an order or payment message no)
     */
    public function __construct(
        public readonly int $storeId,
        public readonly string $templateId,
        public readonly string $sender,
        public readonly string $recipient,
        public readonly \Closure $content,
        public readonly bool $useCooldown = true,
        public readonly ?\Closure $isRecipientCurrent = null,
        public readonly string $channel = Channel::EMAIL,
        public readonly bool $stoppable = true
    ) {
    }

    /**
     * A plain-text notification on a channel without templates: no email template or sender identity, the text is
     * DeliveryContent::$text.
     *
     * @param string $channel the provider's channel code
     * @param \Closure(): DeliveryContent $content
     * @param (\Closure(): bool)|null $isRecipientCurrent
     */
    public static function text(
        int $storeId,
        string $channel,
        string $recipient,
        \Closure $content,
        bool $useCooldown = true,
        ?\Closure $isRecipientCurrent = null,
        bool $stoppable = true
    ): self {
        return new self($storeId, '', '', $recipient, $content, $useCooldown, $isRecipientCurrent, $channel,
            $stoppable);
    }
}
