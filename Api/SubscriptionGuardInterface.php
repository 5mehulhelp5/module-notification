<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * For a feature's atomic claim: put this condition into the same UPDATE, e.g.
 *
 *   UPDATE ck_abandoned_cart SET reminded_at = NOW() WHERE quote_id = 42 AND <validCondition('7')>
 *
 * Either the update sees the subscription withdrawn and changes nothing, or it finishes first.
 *
 * @api
 */
interface SubscriptionGuardInterface
{
    /**
     * SQL condition: the subscription exists and its consent is valid (CommerceKings_Consent rules).
     * @param string $subscriptionIdColumn trusted SQL identifier/expression or an adapter-quoted value
     */
    public function validCondition(string $subscriptionIdColumn): string;

    public function isValid(int $subscriptionId, string $type): bool;
}
