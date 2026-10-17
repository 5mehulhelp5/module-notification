<?php
declare(strict_types=1);

namespace Laminas\Mail\Transport;

/** Test double of laminas/laminas-mail (Magento 2.4.4-2.4.7), loaded only when the real package is absent. */
interface TransportInterface
{
    public function send(\Laminas\Mail\Message $message);
}
