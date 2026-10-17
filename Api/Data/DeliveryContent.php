<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api\Data;

class DeliveryContent
{
    /**
     * @param array<string, mixed> $vars template variables
     * @param string|null $oneClickUnsubscribeUrl HTTPS URL accepting RFC 8058 POSTs; only providers whose
     *     supportsOneClickUnsubscribe() is true receive it (they add List-Unsubscribe headers)
     * @param string|null $text plain text for channels without templates
     */
    public function __construct(
        public readonly array $vars,
        public readonly ?string $recipientName = null,
        public readonly ?string $oneClickUnsubscribeUrl = null,
        public readonly ?string $text = null
    ) {
    }
}
