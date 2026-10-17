<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Fixture;

/** A Laminas sender that records the header block it would write. */
class RecordingSender implements \Laminas\Mail\Transport\TransportInterface
{
    public array $sent = [];

    public function send(\Laminas\Mail\Message $message)
    {
        $this->sent[] = $message->getHeaders()->toString();
    }

    public function setOptions(string $options): string
    {
        return 'options ' . $options;
    }
}
