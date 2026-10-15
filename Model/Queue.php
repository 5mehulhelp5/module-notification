<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\QueueInterface;
use Magento\Framework\MessageQueue\PublisherInterface;

class Queue implements QueueInterface
{
    public const TOPIC = 'commercekings.notification.send';

    public function __construct(
        private readonly PublisherInterface $publisher
    ) {
    }

    public function publish(string $type, string $reference): void
    {
        if (!preg_match('/^[a-z0-9_]+$/D', $type) || $reference === '') {
            throw new \InvalidArgumentException('A notification needs a type code and a reference.');
        }
        $this->publisher->publish(self::TOPIC, $type . '|' . $reference);
    }
}
