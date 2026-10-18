<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Console;

use CommerceKings\Notification\Console\Command\KeyChangeCleanup;
use CommerceKings\Notification\Model\KeyChangeCleanup as Cleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class KeyChangeCleanupTest extends TestCase
{
    public function testDryRunOnlyCounts(): void
    {
        $cleanup = $this->createMock(Cleanup::class);
        $cleanup->expects($this->once())->method('count')->willReturn(['blocks' => 3, 'cooldowns' => 8]);
        $cleanup->expects($this->never())->method('execute');
        $tester = new CommandTester(new KeyChangeCleanup($cleanup));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertSame('Rows of an earlier key: 3 do-not-contact block(s), 8 cooldown row(s). Nothing removed.'
            . PHP_EOL, $tester->getDisplay());
    }

    public function testRemovesAndReportsTheNotice(): void
    {
        $cleanup = $this->createMock(Cleanup::class);
        $cleanup->expects($this->never())->method('count');
        $cleanup->expects($this->once())->method('execute')->willReturn(['blocks' => 3, 'cooldowns' => 8]);
        $tester = new CommandTester(new KeyChangeCleanup($cleanup));
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Removed 3 do-not-contact block(s) and 8 cooldown row(s)', $tester->getDisplay());
        $this->assertStringContainsString('admin notification', $tester->getDisplay());
    }

    public function testNothingOldMentionsNoNotice(): void
    {
        $cleanup = $this->createStub(Cleanup::class);
        $cleanup->method('execute')->willReturn(['blocks' => 0, 'cooldowns' => 0]);
        $tester = new CommandTester(new KeyChangeCleanup($cleanup));
        $tester->execute([]);
        $this->assertStringNotContainsString('admin notification', $tester->getDisplay());
    }
}
