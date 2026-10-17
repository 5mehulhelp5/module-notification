<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;

/**
 * Sends one notification: optional recipient cooldown claim, recipient re-check, content built in the delivery's
 * store, then the provider selected for the channel. A notification the provider accepted can't be recalled - a
 * later failure must never cause a second one, so it surfaces as MessageSentException.
 */
class Sender implements SenderInterface
{
    public function __construct(
        private readonly ProviderPoolInterface $providers,
        private readonly Emulation $emulation,
        private readonly CooldownInterface $cooldown,
        private readonly Config $config
    ) {
    }

    public function send(Delivery $delivery): string
    {
        // Email keeps the plain address as its cooldown key; other channels are kept apart by a prefix.
        $key = $delivery->channel === Channel::EMAIL ? $delivery->recipient : $delivery->channel . ':' . $delivery->recipient;
        $claim = null;
        if ($delivery->useCooldown && $this->config->getCooldownDays() > 0) {
            $claim = $this->cooldown->claim($key);
            if ($claim === null) {
                return self::COOLDOWN;
            }
        }
        $sent = false;
        try {
            // A replaced resolver may read an external recipient source. Recheck it after claiming too.
            if ($delivery->isRecipientCurrent !== null && !($delivery->isRecipientCurrent)()) {
                if ($claim !== null) {
                    $this->cooldown->release($key, $claim);
                }
                return self::RECIPIENT_CHANGED;
            }
            $provider = $this->providers->getSelected($delivery->storeId, $delivery->channel);
            $this->emulation->startEnvironmentEmulation($delivery->storeId, Area::AREA_FRONTEND, true);
            try {
                $provider->send($delivery, ($delivery->content)());
                $sent = true;
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        } catch (MessageSentException $e) {
            throw $e; // the provider accepted it: keep the claim
        } catch (\Throwable $e) {
            if ($sent) {
                // The provider accepted it. A later emulation cleanup error must not enable a duplicate.
                throw new MessageSentException('Notification sent; post-send cleanup failed.', 0, $e);
            }
            if ($claim !== null) {
                $this->cooldown->release($key, $claim);
            }
            throw $e;
        }
        return self::SENT;
    }
}
