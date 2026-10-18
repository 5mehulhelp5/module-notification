<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Console\Command;

use CommerceKings\Notification\Model\KeyChangeCleanup as Cleanup;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** After `encryption:key:change`: removes blocks and cooldown rows of earlier keys (see Model\KeyChangeCleanup). */
class KeyChangeCleanup extends Command
{
    public function __construct(private readonly Cleanup $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('notification:key-change:cleanup')
            ->setDescription('After encryption:key:change: remove cooldown rows of earlier keys; with --force, blocks of keys no longer in env.php')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the rows per key, remove nothing')
            ->addOption('force', null, InputOption::VALUE_NONE,
                'Also remove blocks and cooldown rows of keys no longer listed in env.php (only if you removed old keys from it)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('dry-run')) {
            foreach ($this->cleanup->count() as $table => $rows) {
                $output->writeln(sprintf('%s: %d current key, %d earlier key in env.php, %d unknown key, %d unmarked.',
                    $table === 'blocks' ? 'Do-not-contact blocks' : 'Cooldown rows',
                    $rows['current'], $rows['earlier'], $rows['unknown'], $rows['unmarked']));
            }
            $output->writeln('Nothing removed. Blocks of an earlier key in env.php keep working (moved to the newest key'
                . ' when matched). The cleanup removes earlier-key cooldown rows; "unknown key" rows (a key no longer in'
                . ' env.php) only with --force. Many unknown and no earlier rows can mean this server has the wrong'
                . ' env.php.');
            return Cli::RETURN_SUCCESS;
        }
        $removed = $this->cleanup->execute((bool)$input->getOption('force'));
        $output->writeln(sprintf('Removed %d do-not-contact block(s) and %d cooldown row(s) of %s.',
            $removed['blocks'], $removed['cooldowns'], $input->getOption('force')
                ? 'keys no longer in env.php (and earlier-key cooldowns)' : 'earlier keys (cooldowns only; blocks keep working)'));
        if ($removed['notified']) {
            $output->writeln('An admin notification tells the shop owner.');
        } elseif ($removed['blocks'] > 0) {
            $output->writeln('<comment>No admin notification was posted (Magento_AdminNotification is off, or posting'
                . ' failed – see the log). Tell the shop owner that stopped recipients may get emails again.</comment>');
        }
        return Cli::RETURN_SUCCESS;
    }
}
