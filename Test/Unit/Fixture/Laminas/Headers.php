<?php
declare(strict_types=1);

namespace Laminas\Mail;

use Laminas\Mail\Header\GenericHeader;

/** Test double of laminas/laminas-mail (Magento 2.4.4-2.4.7), loaded only when the real package is absent. */
class Headers
{
    /** @var GenericHeader[] */
    private array $headers = [];

    /** @return GenericHeader[] */
    public function all(): array
    {
        return $this->headers;
    }

    /** Like Laminas: false when absent, the header, or an ArrayIterator when it occurs more than once. */
    public function get(string $name): GenericHeader|\ArrayIterator|false
    {
        $found = array_values(array_filter($this->headers, fn ($h) => strcasecmp($h->getFieldName(), $name) === 0));
        return count($found) > 1 ? new \ArrayIterator($found) : ($found[0] ?? false);
    }

    public function removeHeader(string $name): bool
    {
        $count = count($this->headers);
        $this->headers = array_values(array_filter($this->headers, fn ($h) => strcasecmp($h->getFieldName(), $name) !== 0));
        return count($this->headers) < $count;
    }

    public function addHeaderLine(string $name, string $value): self
    {
        $this->headers[] = new GenericHeader($name, $value);
        return $this;
    }

    /** Like Laminas: a UTF-8 unstructured header is MIME-encoded on the wire, an ASCII one is written as is. */
    public function toString(): string
    {
        $lines = '';
        foreach ($this->headers as $h) {
            $value = $h->getEncoding() === 'ASCII' ? $h->getFieldValue()
                : '=?UTF-8?Q?' . quoted_printable_encode($h->getFieldValue()) . '?=';
            $lines .= $h->getFieldName() . ': ' . $value . "\r\n";
        }
        return $lines;
    }
}
