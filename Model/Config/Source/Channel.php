<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model\Config\Source;

use CommerceKings\Notification\Api\Channel as Channels;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Email and the channels of the installed providers (e.g. sms from an SMS provider module): Notification's own channels
 * by name, any other by its code.
 */
class Channel implements OptionSourceInterface
{
    public function __construct(
        private readonly ProviderPoolInterface $pool
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [Channels::EMAIL => ['value' => Channels::EMAIL, 'label' => __('Email')]];
        foreach ($this->pool->getAll() as $provider) {
            $channel = $provider->getChannel();
            $options[$channel] ??= ['value' => $channel, 'label' => $this->label($channel)];
        }
        return array_values($options);
    }

    private function label(string $channel): \Magento\Framework\Phrase|string
    {
        return match ($channel) {
            Channels::SMS => __('SMS'),
            Channels::WHATSAPP => __('WhatsApp'),
            Channels::VIBER => __('Viber'),
            default => $channel,
        };
    }
}
