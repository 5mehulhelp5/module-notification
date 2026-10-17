<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Fixture;

/** Magento\Framework\Mail\Message as in 2.4.4-2.4.7: the Laminas message in a protected property, no header API. */
class LegacyEmailMessage
{
    protected $zendMessage;

    public function __construct()
    {
        $this->zendMessage = new \Laminas\Mail\Message();
    }

    /** Test access only; Magento has no such method. */
    public function laminas(): \Laminas\Mail\Message
    {
        return $this->zendMessage;
    }
}
