<?php
declare(strict_types=1);

namespace Laminas\Mail;

/** Test double of laminas/laminas-mail (Magento 2.4.4-2.4.7), loaded only when the real package is absent. */
class Message
{
    private Headers $headers;

    public function __construct()
    {
        $this->headers = new Headers();
    }

    public function getHeaders(): Headers
    {
        return $this->headers;
    }

    /** Like Laminas: the message encoding is set on every header. */
    public function setEncoding(string $encoding): self
    {
        foreach ($this->headers->all() as $header) {
            $header->setEncoding($encoding);
        }
        return $this;
    }
}
