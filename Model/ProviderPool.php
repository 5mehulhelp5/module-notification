<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Provider modules add theirs in di.xml (argument "providers": code => ProviderInterface).
 *
 * @api the "providers" di.xml argument is a stable extension point
 */
class ProviderPool implements ProviderPoolInterface
{
    /** The channel's provider when the admin selected none or one that is no longer installed. */
    private const DEFAULTS = [Channel::EMAIL => 'magento_email'];

    /** @var array<string, ProviderInterface> */
    private readonly array $providers;

    /** @var array<string, true> channels whose ambiguous fallback was already logged in this process */
    private array $warned = [];

    /** @param ProviderInterface[] $providers */
    public function __construct(
        private readonly Config $config,
        array $providers = [],
        private readonly ?LoggerInterface $logger = null
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
        // the admin's choice, else the channel's default
        foreach ([$this->config->getProviderCode($storeId, $channel), self::DEFAULTS[$channel] ?? null] as $code) {
            $provider = $code !== null ? $this->get($code) : null;
            if ($provider !== null && $provider->getChannel() === $channel) {
                return $provider;
            }
        }
        // else the first installed provider for the channel
        $candidates = array_values(array_filter($this->providers, fn ($provider) => $provider->getChannel() === $channel));
        if ($candidates === []) {
            throw new LocalizedException(__('No notification provider is available for the %1 channel.', $channel));
        }
        if (count($candidates) > 1 && !isset($this->warned[$channel])) {
            // several installed and none selected: module order decided, which a deployment can change
            $this->warned[$channel] = true;
            $this->logger?->warning(sprintf('CommerceKings_Notification: no provider selected for the "%s" channel;'
                . ' using "%s", the first of %d installed. Select one under Delivery.', $channel,
                $candidates[0]->getCode(), count($candidates)));
        }
        return $candidates[0];
    }
}
