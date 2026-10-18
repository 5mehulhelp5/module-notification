<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Cron;

use CommerceKings\Notification\Model\RecipientBlock;
use Psr\Log\LoggerInterface;

/** Lifts do-not-contact blocks without a sign-up attempt for the configured days. */
class CleanUpRecipientBlocks
{
    public function __construct(
        private readonly RecipientBlock $blocks,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $lifted = $this->blocks->cleanUp();
        if ($lifted > 0) {
            $this->logger->info(sprintf('CommerceKings_Notification: lifted %d do-not-contact block(s)', $lifted));
        }
    }
}
