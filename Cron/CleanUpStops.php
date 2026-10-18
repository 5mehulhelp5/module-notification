<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Cron;

use CommerceKings\Notification\Api\StopListInterface;
use Psr\Log\LoggerInterface;

/** Lifts do-not-contact stops without a sign-up attempt for the configured days. */
class CleanUpStops
{
    public function __construct(
        private readonly StopListInterface $stopList,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $lifted = $this->stopList->cleanUp();
        if ($lifted > 0) {
            $this->logger->info(sprintf('CommerceKings_Notification: lifted %d do-not-contact stop(s)', $lifted));
        }
    }
}
