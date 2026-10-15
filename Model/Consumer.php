<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Psr\Log\LoggerInterface;

/**
 * Queue consumer "commerceKingsNotificationSend". Message = "<type>|<reference>". The type re-checks its
 * own rules on fresh data; its exceptions reach Magento's consumer (logged, message rejected).
 */
class Consumer
{
    public function __construct(
        private readonly TypePool $types,
        private readonly LoggerInterface $logger
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
        $type->process($reference);
    }
}
