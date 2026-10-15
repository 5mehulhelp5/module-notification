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
    /** The contact for this address, created on first use. */
    public function save(int $websiteId, string $email): int;

    public function getEmail(int $contactId): ?string;
}
