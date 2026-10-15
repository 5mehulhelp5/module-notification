<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api\Data;

/**
 * What a subscription type authorized. $condition is repeated inside the write, so a reference that
 * changed after authorize() (another store, ordered, assigned to a customer) is not written.
 *
 * @api
 */
class Target
{
    /**
     * @param int $referenceId the feature's own id (quote id, product id, ...)
     * @param int $storeId store the notification will be sent from
     * @param bool $accepting false (e.g. the feature is off in that store): treated as unsubscribe
     * @param string $condition trusted SQL condition, '' for none; never built from request input
     */
    public function __construct(
        public readonly int $referenceId,
        public readonly int $storeId,
        public readonly bool $accepting = true,
        public readonly string $condition = ''
    ) {
    }
}
