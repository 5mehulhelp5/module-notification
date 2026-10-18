<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * Sends one notification: a stoppable delivery to a stopped recipient is dropped, and one in quiet hours is held
 * (before any claim); then the optional recipient cooldown claim, recipient re-check, content built in the delivery's
 * store, and the provider selected for the channel. A one-click unsubscribe URL only reaches providers that support
 * it. A notification the provider accepted can't be recalled - a later failure must never cause a second one, so it
 * surfaces as MessageSentException.
 */
class Sender implements SenderInterface
{
    public function __construct(
        private readonly ProviderPoolInterface $providers,
        private readonly Emulation $emulation,
        private readonly CooldownInterface $cooldown,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly RecipientNormalizerPool $normalizers,
        private readonly StopListInterface $stopList,
        private readonly QuietHoursInterface $quietHours
    ) {
    }

    public function send(Delivery $delivery): string
    {
        // One canonical recipient for the cooldown and the provider. Email keeps the plain address as its cooldown key;
        // other channels are kept apart by a prefix.
        $recipient = $this->normalizers->normalize($delivery->channel, $delivery->recipient);
        if ($recipient === '') {
            // nothing the channel can deliver to (e.g. not a phone number): never claimed, never handed to a provider
            throw new \InvalidArgumentException(sprintf('No valid recipient for a "%s" delivery.', $delivery->channel));
        }
        $delivery = $this->withRecipient($delivery, $recipient);
        if ($delivery->stoppable && $this->stopList->isStopped($delivery->channel, $delivery->recipient)) {
            return self::STOPPED;
        }
        // service messages (not stoppable) are never held
        if ($delivery->stoppable
            && $this->quietHours->isQuiet($delivery->storeId, $delivery->channel, $delivery->recipient)) {
            return self::QUIET_HOURS;
        }
        $key = $delivery->channel === Channel::EMAIL
            ? $delivery->recipient : $delivery->channel . ':' . $delivery->recipient;
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
                $provider->send($delivery, $this->forProvider($provider, $this->withWayOut($delivery, ($delivery->content)(), $provider)));
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

    /**
     * A stoppable notification always has a one-click way out (when the store sends one-click headers and the provider
     * can carry them): without an unsubscribe link of its own, the stop link is used. A provider without one-click
     * (e.g. SMS) gets none, so nothing is dropped or logged. Built inside the store's emulation.
     */
    private function withWayOut(Delivery $delivery, DeliveryContent $content, ProviderInterface $provider): DeliveryContent
    {
        if (!$delivery->stoppable || $content->oneClickUnsubscribeUrl !== null || !$provider->supportsOneClickUnsubscribe()
            || !$this->config->isOneClickUnsubscribeEnabled($delivery->storeId)) {
            return $content;
        }
        $url = $this->stopList->getStopUrl($delivery->channel, $delivery->recipient, $delivery->storeId);
        return $url === '' ? $content : new DeliveryContent($content->vars, $content->recipientName, $url, $content->text);
    }

    /** A provider without one-click support never gets the URL; the notification still goes out with its own link. */
    private function forProvider(ProviderInterface $provider, DeliveryContent $content): DeliveryContent
    {
        if ($content->oneClickUnsubscribeUrl === null || $provider->supportsOneClickUnsubscribe()) {
            return $content;
        }
        $this->logger->warning(sprintf('CommerceKings_Notification: one-click unsubscribe is on, but provider "%s"'
            . ' cannot add its headers; sent without them.', $provider->getCode()));
        return new DeliveryContent($content->vars, $content->recipientName, null, $content->text);
    }

    private function withRecipient(Delivery $delivery, string $recipient): Delivery
    {
        return $recipient === $delivery->recipient ? $delivery : new Delivery($delivery->storeId, $delivery->templateId,
            $delivery->sender, $recipient, $delivery->content, $delivery->useCooldown, $delivery->isRecipientCurrent,
            $delivery->channel, $delivery->stoppable, $delivery->type);
    }
}
