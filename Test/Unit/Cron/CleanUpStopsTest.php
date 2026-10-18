<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Cron;

use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Cron\CleanUpStops;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanUpStopsTest extends TestCase
{
    public function testUsesTheInterfaceSoAReplacementIsCleanedUpToo(): void
    {
        $stopList = $this->createMock(StopListInterface::class);
        $stopList->expects($this->once())->method('cleanUp')->willReturn(3);
        $logs = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($message) use (&$logs) { $logs[] = $message; });
        (new CleanUpStops($stopList, $logger))->execute();
        $this->assertSame(['CommerceKings_Notification: lifted 3 do-not-contact stop(s)'], $logs);
    }

    public function testNothingLiftedLogsNothing(): void
    {
        $stopList = $this->createStub(StopListInterface::class);
        $stopList->method('cleanUp')->willReturn(0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        (new CleanUpStops($stopList, $logger))->execute();
    }
}
