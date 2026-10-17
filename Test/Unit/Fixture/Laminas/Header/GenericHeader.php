<?php
declare(strict_types=1);

namespace Laminas\Mail\Header;

/** Test double of laminas/laminas-mail (Magento 2.4.4-2.4.7), loaded only when the real package is absent. */
class GenericHeader
{
    private string $encoding = 'ASCII';

    public function __construct(private readonly string $name, private readonly string $value)
    {
    }

    public function getFieldName(): string
    {
        return $this->name;
    }

    public function getFieldValue(): string
    {
        return $this->value;
    }

    public function getEncoding(): string
    {
        return $this->encoding;
    }

    public function setEncoding(string $encoding): self
    {
        $this->encoding = strtoupper($encoding);
        return $this;
    }
}
