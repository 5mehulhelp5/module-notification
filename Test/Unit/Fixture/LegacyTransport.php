<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Fixture;

use Magento\Framework\Mail\TransportInterface;

/**
 * Magento\Email\Model\Transport as in 2.4.4-2.4.7: a private Laminas sender, created in the constructor (2.4.4-2.4.5)
 * or by getTransport() on first use (2.4.6-2.4.7); sendMessage() re-reads the message as UTF-8 before handing it over.
 */
class LegacyTransport implements TransportInterface
{
    private $laminasTransport;

    public function __construct(
        private readonly LegacyEmailMessage $message,
        private readonly \Laminas\Mail\Transport\TransportInterface $sender,
        private readonly bool $lazy = false
    ) {
        if (!$lazy) {
            $this->laminasTransport = $sender;
        }
    }

    public function getTransport(): \Laminas\Mail\Transport\TransportInterface
    {
        if (!$this->lazy) {
            throw new \BadMethodCallException('Magento 2.4.4-2.4.5 have no getTransport().');
        }
        return $this->laminasTransport ??= $this->sender;
    }

    public function sendMessage()
    {
        $laminas = $this->message->laminas()->setEncoding('utf-8');
        ($this->lazy ? $this->getTransport() : $this->laminasTransport)->send($laminas);
    }

    public function getMessage()
    {
        return $this->message;
    }
}
