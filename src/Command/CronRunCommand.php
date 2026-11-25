<?php
declare(strict_types=1);

namespace MH1\CronBundle\Command;

use MH1\CronBundle\Service\CronJobFailureProviderInterface;
use MH1\CronBundle\Service\CronJobServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mh1:cron:run')]
class CronRunCommand extends Command
{
    /**
     * @var CronJobServiceInterface
     */
    private $cronJobService;

    public function __construct(CronJobServiceInterface $cronJobService)
    {
        parent::__construct();
        $this->cronJobService = $cronJobService;
    }

    protected function configure(): void
    {
        $this->setDescription('runs scheduled cron jobs');
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Return a failure code if a job fails');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cronJobService->execute();

        $failures = $this->cronJobService instanceof CronJobFailureProviderInterface
            ? $this->cronJobService->getFailures()
            : [];
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        foreach ($failures as $failure) {
            $errorOutput->writeln('<error>' . OutputFormatter::escape($failure) . '</error>');
        }

        return $input->getOption('strict') && $failures !== [] ? Command::FAILURE : Command::SUCCESS;
    }
}
