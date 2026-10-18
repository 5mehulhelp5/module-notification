<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Console\Command;

use CommerceKings\Notification\Model\KeyChangeCleanup as Cleanup;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** After `encryption:key:change`: removes blocks and cooldown rows of the earlier key (see Model\KeyChangeCleanup). */
class KeyChangeCleanup extends Command
{
    public function __construct(private readonly Cleanup $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('notification:key-change:cleanup')
            ->setDescription('After encryption:key:change: remove do-not-contact blocks and cooldown rows of the earlier key')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the rows, remove nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('dry-run')) {
            $old = $this->cleanup->count();
            $output->writeln(sprintf('Rows of an earlier key: %d do-not-contact block(s), %d cooldown row(s).'
                . ' Nothing removed.', $old['blocks'], $old['cooldowns']));
            return Cli::RETURN_SUCCESS;
        }
        $removed = $this->cleanup->execute();
        $output->writeln(sprintf('Removed %d do-not-contact block(s) and %d cooldown row(s) of an earlier key.',
            $removed['blocks'], $removed['cooldowns']));
        if ($removed['blocks'] > 0) {
            $output->writeln('An admin notification tells the shop owner (with Magento_AdminNotification).');
        }
        return Cli::RETURN_SUCCESS;
    }
}
