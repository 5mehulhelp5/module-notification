<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/** @api */
interface QueueInterface
{
    /** Queue one notification for the shared consumer; $type is a code registered in Model\TypePool. */
    public function publish(string $type, string $reference): void;
}
