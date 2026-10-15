<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api\Data;

class MessageContent
{
    /**
     * @param array<string, mixed> $vars template variables
     * @param string|null $oneClickUnsubscribeUrl HTTPS URL accepting RFC 8058 POSTs; adds List-Unsubscribe headers
     */
    public function __construct(
        public readonly array $vars,
        public readonly ?string $recipientName = null,
        public readonly ?string $oneClickUnsubscribeUrl = null
    ) {
    }
}
