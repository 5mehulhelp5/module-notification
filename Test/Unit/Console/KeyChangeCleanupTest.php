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
    public function testDryRunShowsEveryKindAndRemovesNothing(): void
    {
        $cleanup = $this->createMock(Cleanup::class);
        $cleanup->expects($this->once())->method('count')->willReturn([
            'blocks' => ['current' => 1, 'earlier' => 3, 'unknown' => 2, 'unmarked' => 4],
            'cooldowns' => ['current' => 5, 'earlier' => 8, 'unknown' => 0, 'unmarked' => 0],
        ]);
        $cleanup->expects($this->never())->method('execute');
        $tester = new CommandTester(new KeyChangeCleanup($cleanup));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Do-not-contact blocks: 1 current key, 3 earlier key in env.php, 2 unknown key, 4 unmarked.', $display);
        $this->assertStringContainsString('Cooldown rows: 5 current key, 8 earlier key in env.php, 0 unknown key, 0 unmarked.', $display);
        $this->assertStringContainsString('Nothing removed.', $display);
    }

    public function testRemovesEarlierKeysOnlyByDefault(): void
    {
        $cleanup = $this->createMock(Cleanup::class);
        $cleanup->expects($this->once())->method('execute')->with(false)
            ->willReturn(['blocks' => 3, 'cooldowns' => 8, 'notified' => true]);
        $tester = new CommandTester(new KeyChangeCleanup($cleanup));
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Removed 3 do-not-contact block(s) and 8 cooldown row(s) of earlier keys.', $tester->getDisplay());
        $this->assertStringContainsString('An admin notification tells the shop owner.', $tester->getDisplay());
    }

    public function testForceAlsoRemovesUnknownKeys(): void
    {
        $cleanup = $this->createMock(Cleanup::class);
        $cleanup->expects($this->once())->method('execute')->with(true)
            ->willReturn(['blocks' => 0, 'cooldowns' => 2, 'notified' => false]);
        $tester = new CommandTester(new KeyChangeCleanup($cleanup));
        $tester->execute(['--force' => true]);
        $this->assertStringContainsString('of earlier and unknown keys.', $tester->getDisplay());
        $this->assertStringNotContainsString('admin notification', $tester->getDisplay(), 'no blocks: nothing to tell');
    }

    public function testSaysSoWhenNoNoticeWasPosted(): void
    {
        $cleanup = $this->createStub(Cleanup::class);
        $cleanup->method('execute')->willReturn(['blocks' => 3, 'cooldowns' => 0, 'notified' => false]);
        $tester = new CommandTester(new KeyChangeCleanup($cleanup));
        $tester->execute([]);
        $this->assertStringContainsString('No admin notification was posted', $tester->getDisplay());
        $this->assertStringNotContainsString('An admin notification tells', $tester->getDisplay());
    }
}
