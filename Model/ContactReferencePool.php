<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\ContactReferenceInterface;

class ContactReferencePool
{
    /** @param array<string, ContactReferenceInterface> $references */
    public function __construct(
        private readonly array $references = []
    ) {
    }

    /** @return ContactReferenceInterface[] */
    public function getAll(): array
    {
        return array_values(array_filter($this->references, fn ($r) => $r instanceof ContactReferenceInterface));
    }
}
