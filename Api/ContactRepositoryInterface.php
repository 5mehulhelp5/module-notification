<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Guest email addresses for notifications, one per website and address (customers use their account).
 *
 * @api
 */
interface ContactRepositoryInterface
{
    /**
     * The contact for this address, created on first use. One contact per website and address: letter case and
     * surrounding spaces are ignored, every other difference (e.g. an accent) is another address.
     */
    public function save(int $websiteId, string $email): int;

    public function getEmail(int $contactId): ?string;
}
