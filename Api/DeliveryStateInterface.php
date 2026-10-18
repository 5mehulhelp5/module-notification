<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Send state per subscription key - type, reference and subscriber, the unique key of a subscription - not per
 * subscription id: a withdrawal deletes the subscription and subscribing again creates a new one, but "already sent"
 * must survive that. Notification only stores it; what "due" means (once, every N days, a retry backoff, an hourly
 * cap) stays the feature's.
 *
 * The table (self::TABLE) is a contract, so a feature can claim with one atomic UPDATE that joins it, e.g.
 *
 *   UPDATE ck_notification_delivery_state AS d INNER JOIN ck_notification_subscription AS s ON <joinCondition('d', 's')>
 *   SET d.sent_at = NOW(), d.queued_at = NULL, d.delivery_version = d.delivery_version + 1
 *   WHERE <keyCondition($type, $referenceId, '', 'd')> AND d.sent_at IS NULL AND <the feature's own conditions>
 *
 * Columns: type, reference_id, subject_key (the key; subject_key is '' for single types, else the subscription's
 * c:<contact> or u:<customer>); queued_at (waiting in the queue), sent_at (last sent), skip_until (leave it out until
 * then), failed_sends (failed attempts in a row), delivery_version (raised by every claim, so a late worker cannot
 * undo a newer one) and updated_at. All times UTC, like the database session's NOW(). No row means never queued.
 *
 * Rows go when their reference does (the type's SubscriptionTypeInterface::orphanCondition(), daily); rows of a
 * person (subject_key not '') also go RETENTION_DAYS after their last change once no subscription has that key. The
 * cleanup covers subscription types only: a module that keeps state for another type (e.g. an opt-in confirmation
 * keyed by contact id) removes its rows itself.
 *
 * @api
 */
interface DeliveryStateInterface
{
    public const TABLE = 'ck_notification_delivery_state';

    public const RETENTION_DAYS = 90;

    /** The table's name (with Magento's table prefix). */
    public function getTableName(): string;

    /**
     * SQL ON condition joining the state (its alias) to a subscription (its alias) by the subscription key.
     *
     * @param string $stateAlias SQL identifier the query gives the state table
     * @param string $subscriptionAlias SQL identifier the query gives ck_notification_subscription
     */
    public function joinCondition(string $stateAlias, string $subscriptionAlias): string;

    /**
     * WHERE conditions for one key, with bound values, for Magento's adapter (update(), delete(), Select::where()).
     *
     * @param string $alias the state's alias in the query; '' for a single-table statement
     * @return array<int|string, mixed>
     */
    public function keyCondition(string $type, int $referenceId, string $subjectKey = '', string $alias = ''): array;

    /** Creates the key's row before its first conditional UPDATE; an existing row is kept. */
    public function ensure(string $type, int $referenceId, string $subjectKey = ''): void;

    /** @return array<string, mixed>|null the key's row, or null when there is none (never queued) */
    public function load(string $type, int $referenceId, string $subjectKey = ''): ?array;
}
