<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use Magento\Framework\Exception\LocalizedException;

/** Provider modules add theirs in di.xml (argument "providers": code => ProviderInterface). */
class ProviderPool implements ProviderPoolInterface
{
    /** The channel's provider when the admin selected none or one that is no longer installed. */
    private const DEFAULTS = [Channel::EMAIL => 'magento_email'];

    /** @var array<string, ProviderInterface> */
    private readonly array $providers;

    /** @param ProviderInterface[] $providers */
    public function __construct(
        private readonly Config $config,
        array $providers = []
    ) {
        $byCode = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof ProviderInterface) {
                throw new \InvalidArgumentException('Notification providers must implement ProviderInterface.');
            }
            $byCode[$provider->getCode()] = $provider;
        }
        $this->providers = $byCode;
    }

    public function getAll(): array
    {
        return $this->providers;
    }

    public function get(string $code): ?ProviderInterface
    {
        return $this->providers[$code] ?? null;
    }

    public function getSelected(int $storeId, string $channel): ProviderInterface
    {
        // the admin's choice, else the channel's default, else the first installed provider for the channel
        $codes = [$this->config->getProviderCode($storeId, $channel), self::DEFAULTS[$channel] ?? null,
            ...array_keys($this->providers)];
        foreach ($codes as $code) {
            $provider = $code !== null ? $this->get($code) : null;
            if ($provider !== null && $provider->getChannel() === $channel) {
                return $provider;
            }
        }
        throw new LocalizedException(__('No notification provider is available for the %1 channel.', $channel));
    }
}
