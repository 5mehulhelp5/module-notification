<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\SubscriptionGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer "commerceKingsNotificationSend". Message = "<type>|<reference>". The type re-checks its
 * own rules on fresh data; its exceptions reach Magento's consumer (logged, message rejected).
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
            $this->logger->error(sprintf('CommerceKings_Notification: unknown notification "%s" dropped', $code));
            return;
        }
        if ($this->subscriptionTypes->get($code) !== null
            && (!ctype_digit($reference) || !$this->subscriptions->isValid((int)$reference, $code))) {
            $this->logger->info(sprintf('CommerceKings_Notification: %s %s skipped (no valid subscription)', $code, $reference));
            return;
        }
        $type->process($reference);
    }
}
