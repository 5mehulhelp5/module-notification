<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * For a feature's atomic claim: put this condition into the same UPDATE, e.g.
 *
 *   UPDATE ck_notification_delivery_state SET sent_at = NOW()
 *   WHERE type = 'cart_reminder' AND reference_id = 42 AND subject_key = '' AND <validCondition('7')>
 *
 * Either the update sees the subscription withdrawn and changes nothing, or it finishes first.
 *
 * @api
 */
interface SubscriptionGuardInterface
{
    /**
     * SQL condition: the subscription exists and its permission is valid (SubscriptionPermissionInterface).
     * @param string $subscriptionIdColumn trusted SQL identifier/expression or an adapter-quoted value
     */
    public function validCondition(string $subscriptionIdColumn): string;

    public function isValid(int $subscriptionId, string $type): bool;
}
