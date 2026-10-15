<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * One kind of notification (e.g. a cart reminder), registered by its module in di.xml
 * (Model\TypePool "types": code => implementation). The shared consumer hands it the reference it was
 * queued with; it re-checks its own rules on fresh data and sends through MailerInterface.
 *
 * @api
 */
interface NotificationTypeInterface
{
    /** Messages can arrive twice or late: re-check everything, claim atomically, then send. */
    public function process(string $reference): void;
}
