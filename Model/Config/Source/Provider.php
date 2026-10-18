<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model\Config\Source;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The installed providers of one channel: email, or another through a virtualType (argument "channel").
 *
 * @api a virtual type with the "channel" argument is a stable extension point
 */
class Provider implements OptionSourceInterface
{
    public function __construct(
        private readonly ProviderPoolInterface $pool,
        private readonly string $channel = Channel::EMAIL
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->pool->getAll() as $code => $provider) {
            if ($provider->getChannel() === $this->channel) {
                $options[] = ['value' => $code, 'label' => $provider->getLabel()];
            }
        }
        return $options;
    }
}
