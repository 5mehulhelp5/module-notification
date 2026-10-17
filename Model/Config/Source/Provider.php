<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model\Config\Source;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use Magento\Framework\Data\OptionSourceInterface;

/** The installed email providers. */
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
