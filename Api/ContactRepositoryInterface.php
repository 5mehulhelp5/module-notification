<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Guest recipients for notifications – an email address, a phone number, … – one per website, channel and address
 * (customers use their account).
 *
 * @api
 */
interface ContactRepositoryInterface
{
    /**
     * The contacts table (contact_id, website_id, channel, recipient, ...), named for a module's own cleanup, e.g.
     * deleting its rows whose contact no longer exists; rows are written only through this interface.
     */
    public const TABLE = 'ck_notification_contact';

    /**
     * The contact for this address on the channel, created on first use. One contact per website, channel and
     * address in its channel's normal form (email: letter case and surrounding spaces ignored, every other difference
     * – e.g. an accent – is another address; other channels: their RecipientNormalizerInterface).
     */
    public function save(int $websiteId, string $recipient, string $channel = Channel::EMAIL): int;

    /** The existing contact for this address on the channel, null when none; never creates one. */
    public function find(int $websiteId, string $recipient, string $channel = Channel::EMAIL): ?int;

    /** The contact's address, only when it is an email contact. */
    public function getEmail(int $contactId): ?string;

    /** @return array{channel: string, recipient: string}|null */
    public function getRecipient(int $contactId): ?array;
}
