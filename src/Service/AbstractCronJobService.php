<?php
declare(strict_types=1);

namespace MH1\CronBundle\Service;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use MH1\CronBundle\Command\AbstractCronCommand;
use MH1\CronBundle\Helper\DateTimeHelper;
use MH1\CronBundle\Model\CronJobInterface;
use MH1\CronBundle\Model\CronJobProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

abstract class AbstractCronJobService implements CronJobServiceInterface, CronJobFailureProviderInterface
{
    public const TIMEOUT_EXIT_CODE = 124;

    /** @var list<string> */
    private array $failures = [];

    /**
     * @var CronJobLogServiceInterface
     */
    protected $cronJobLogService;

    /**
     * @var string
     */
    protected $consolePath;

    /**
     * @var int
     */
    private $checkInterval;

    /**
     * @var string
     */
    protected $executionTimeZone;

    /**
     * @var string|null
     */
    private $phpExecutablePath;

    private ?float $processTimeout = null;

    /**
     * @param CronJobLogServiceInterface $cronJobLogService
     * @param string                     $consolePath       path to bin/console e.g. "/var/www/project/bin/console"
     * @param int                        $checkInterval     milliseconds between process checks (greater than 1)
     * @param string|null                $executionTimeZone name of the timezone to check for the runtime
     *                                                      if null php default timezone is used
     * @param string|null                $phpExecutablePath path to php executable e.g. /usr/local/php/bin/php
     *                                                      usefully if multiple PHP versions running on the same system
     */
    public function __construct(
        CronJobLogServiceInterface $cronJobLogService,
        string                     $consolePath,
        int                        $checkInterval,
        ?string                    $executionTimeZone = null,
        ?string                    $phpExecutablePath = null
    ) {
        if ($checkInterval <= 1) {
            throw new InvalidArgumentException(
                'checkInterval must be greater than 1 millisecond to prevent errors'
            );
        }

        $this->cronJobLogService = $cronJobLogService;
        $this->consolePath = $consolePath;
        $this->checkInterval = $checkInterval * 1000;
        $this->executionTimeZone = $executionTimeZone ?? date_default_timezone_get();
        $this->phpExecutablePath = $phpExecutablePath;
    }

    /**
     * @inheritDoc
     */
    public function execute(?iterable $cronJobs = null): void
    {
        $this->failures = [];
        $scheduleTime = null;
        if ($cronJobs === null) {
            $scheduleTime = DateTimeHelper::getUTCDateTimeImmutable();
            $cronJobs = $this->getScheduledJobs($scheduleTime);
        }

        $processes = [];
        foreach ($cronJobs as $cronJob) {
            if ($scheduleTime !== null && !$this->claimScheduledJob($cronJob, $scheduleTime)) {
                continue;
            }

            $log = $this->cronJobLogService->logStart($cronJob);

            try {
                $process = $this->createProcess($cronJob);
                $process->start();
            } catch (Exception $exception) {
                $message = $exception->getMessage();
                $this->addFailure($cronJob, $message);
                $this->cronJobLogService->logEnd($log, Command::FAILURE, $message);
                continue;
            }
            $processes[] = new CronJobProcess($process, $log);
        }

        $this->checkRunning($processes);
    }

    public function getFailures(): array
    {
        return $this->failures;
    }

    protected function claimScheduledJob(CronJobInterface $job, DateTimeInterface $scheduleTime): bool
    {
        return true;
    }

    public function setProcessTimeout(?float $processTimeout): void
    {
        $this->processTimeout = $processTimeout;
    }

    /**
     * @param CronJobInterface $cronJob
     *
     * @return Process
     */
    public function createProcess(CronJobInterface $cronJob): Process
    {
        $processParts = [];
        if ($this->phpExecutablePath !== null && $this->phpExecutablePath !== '') {
            $processParts[] = $this->phpExecutablePath;
        }

        $processParts[] = $this->consolePath;
        $processParts[] = $cronJob->getCommand();

        return new Process($processParts, timeout: $this->processTimeout);
    }

    /**
     * @param DateTimeInterface|null $scheduleDateTime
     *
     * @return CronJobInterface[]
     */
    public function getScheduledJobs(?DateTimeInterface $scheduleDateTime = null): array
    {
        $this->failures = [];
        $scheduleDateTime = $scheduleDateTime ?? DateTimeHelper::getUTCDateTimeImmutable();
        $scheduledJobs = [];
        foreach ($this->getEnabledJobs() as $job) {
            try {
                if ($this->shouldJobRun($job, $scheduleDateTime)) {
                    $scheduledJobs[] = $job;
                }
            } catch (InvalidArgumentException $exception) {
                $this->addFailure($job, $exception->getMessage());
            }
        }

        return $scheduledJobs;
    }

    /**
     * @return CronJobInterface[]
     */
    abstract public function getEnabledJobs(): array;

    /**
     * @param CronJobInterface       $job
     * @param DateTimeInterface|null $dateTime
     *
     * @return bool
     */
    protected function shouldJobRun(CronJobInterface $job, ?DateTimeInterface $dateTime = null): bool
    {
        return $this->getScheduledTime($job, $dateTime ?? DateTimeHelper::getUTCDateTimeImmutable()) !== null;
    }

    protected function getScheduledTime(CronJobInterface $job, DateTimeInterface $time): ?DateTimeInterface
    {
        $cron = new CronExpression($job->getSchedule());
        $offset = (new DateTimeZone($this->executionTimeZone))->getOffset($time);
        $localTime = new DateTimeImmutable('@' . ($time->getTimestamp() + $offset));

        return $cron->isDue($localTime, 'UTC') ? $time : null;
    }

    /**
     * @param CronJobProcess[] $processes
     */
    protected function checkRunning(array $processes): void
    {
        while ($processes !== []) {
            $nextCheck = microtime(true) + $this->checkInterval / 1_000_000;
            foreach ($processes as $index => $running) {
                $process = $running->getProcess();
                $report = $running->getReport();

                try {
                    if ($process->isRunning()) {
                        $process->checkTimeout();
                        $nextCheck = $this->getNextProcessCheck($process, $nextCheck);
                        continue;
                    }

                    $exitCode = $process->getExitCode();
                    $output = $this->getProcessOutput($process);
                    if ($exitCode !== Command::SUCCESS && $exitCode !== AbstractCronCommand::SKIPPED) {
                        $message = $exitCode === null
                            ? 'Process did not return an exit code.'
                            : sprintf('Process exited with code %d.', $exitCode);
                        $this->addFailure($report->getCronJob(), $message);
                    }
                } catch (ProcessTimedOutException $exception) {
                    $exitCode = self::TIMEOUT_EXIT_CODE;
                    $this->addFailure($report->getCronJob(), $exception->getMessage());
                    $output = $this->getProcessOutput($process) . PHP_EOL . $exception->getMessage();
                } catch (Exception $exception) {
                    $exitCode = Command::FAILURE;
                    $output = $exception->getMessage();
                    $this->addFailure($report->getCronJob(), $output);
                }

                $this->cronJobLogService->logEnd($report, $exitCode, $output);
                unset($processes[$index]);
            }

            if ($processes !== []) {
                usleep(max(0, (int) ceil(($nextCheck - microtime(true)) * 1_000_000)));
            }
        }
    }

    private function getNextProcessCheck(Process $process, float $nextCheck): float
    {
        $timeout = $process->getTimeout();
        if ($timeout !== null) {
            $nextCheck = min($nextCheck, $process->getStartTime() + $timeout);
        }

        $idleTimeout = $process->getIdleTimeout();
        if ($idleTimeout !== null) {
            $lastOutput = $process->getLastOutputTime() ?? $process->getStartTime();
            $nextCheck = min($nextCheck, $lastOutput + $idleTimeout);
        }

        return $nextCheck;
    }

    private function getProcessOutput(Process $process): string
    {
        if ($process->isOutputDisabled()) {
            return '';
        }

        return $process->getOutput() . PHP_EOL . PHP_EOL . $process->getErrorOutput();
    }

    private function addFailure(CronJobInterface $job, string $message): void
    {
        $this->failures[] = sprintf('%s: %s', $job->getCommand(), $message);
    }
}
