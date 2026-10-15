<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Cron;

use CommerceKings\Notification\Model\Cooldown;

/** Prune cooldown rows after the configured cooldown. */
class CleanUpCooldown
{
    public function __construct(private readonly Cooldown $cooldown)
    {
    }

    public function execute(): int
    {
        return $this->cooldown->cleanUp();
    }
}
