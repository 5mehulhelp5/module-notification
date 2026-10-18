<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\SubscriptionGuardInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\MessageQueue\ConnectionLostException;
use Magento\Framework\MessageQueue\MessageLockException;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer "commerceKingsNotificationSend". Message = "<type>|<reference>". The type re-checks its
 * own rules on fresh data; its exceptions reach Magento's consumer (logged, message rejected) without their message,
 * which can contain the recipient address (SMTP errors).
 * Subscription types are queued with the subscription id and only handed over while it is still valid.
 */
class Consumer
{
    public function __construct(
        private readonly TypePool $types,
        private readonly LoggerInterface $logger,
        private readonly SubscriptionTypePool $subscriptionTypes,
        private readonly SubscriptionGuardInterface $subscriptions
    ) {
    }

    public function process(string $message): void
    {
        [$code, $reference] = array_pad(explode('|', $message, 2), 2, '');
        $type = $this->types->get($code);
        if ($type === null || $reference === '') {
            // e.g. the owning module was removed while messages were waiting
            $this->logger->error(preg_match('/^[a-z0-9_]+$/D', $code)
                ? sprintf('CommerceKings_Notification: unknown notification "%s" dropped', $code)
                : 'CommerceKings_Notification: notification with an invalid code dropped');
            return;
        }
        if ($this->subscriptionTypes->get($code) !== null
            && (!ctype_digit($reference) || !$this->subscriptions->isValid((int)$reference, $code))) {
            $this->logger->info(sprintf('CommerceKings_Notification: %s %s skipped (no valid subscription)', $code,
                ctype_digit($reference) ? $reference : 'with a non-numeric reference'));
            return;
        }
        try {
            $type->process($reference);
        } catch (MessageLockException | ConnectionLostException | NotFoundException $error) {
            throw $error;
        } catch (\Throwable $error) {
            // an \Error (e.g. a TypeError in a provider) too: wrapped, so the consumer rejects the message and keeps running
            throw new \RuntimeException(sprintf('CommerceKings_Notification: %s %s failed (%s, code %s, at %s:%d)',
                $code, ctype_digit($reference) ? $reference : 'with a non-numeric reference', get_class($error),
                $error->getCode(), $error->getFile(), $error->getLine()));
        }
    }
}
