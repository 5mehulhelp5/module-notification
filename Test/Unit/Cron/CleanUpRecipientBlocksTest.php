<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Cron;

use CommerceKings\Notification\Api\RecipientBlockInterface;
use CommerceKings\Notification\Cron\CleanUpRecipientBlocks;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanUpRecipientBlocksTest extends TestCase
{
    public function testUsesTheInterfaceSoAReplacementIsCleanedUpToo(): void
    {
        $blocks = $this->createMock(RecipientBlockInterface::class);
        $blocks->expects($this->once())->method('cleanUp')->willReturn(3);
        $logs = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($message) use (&$logs) { $logs[] = $message; });
        (new CleanUpRecipientBlocks($blocks, $logger))->execute();
        $this->assertSame(['CommerceKings_Notification: lifted 3 do-not-contact block(s)'], $logs);
    }

    public function testNothingLiftedLogsNothing(): void
    {
        $blocks = $this->createStub(RecipientBlockInterface::class);
        $blocks->method('cleanUp')->willReturn(0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        (new CleanUpRecipientBlocks($blocks, $logger))->execute();
    }
}
