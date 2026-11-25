<?php
declare(strict_types=1);

namespace MH1\CronBundle\Tests\Integration;

use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use MH1\CronBundle\Command\CronRunCommand;
use MH1\CronBundle\Entity\Mh1CronJob;
use MH1\CronBundle\Entity\Mh1CronJobReport;
use MH1\CronBundle\Entity\Mh1CronJobSlot;
use MH1\CronBundle\Model\CronJobInterface;
use MH1\CronBundle\Service\DoctrineCronJobLogService;
use MH1\CronBundle\Service\DoctrineCronJobService;
use RuntimeException;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class DoctrineCronJobRunnerTest extends DoctrineTestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->setClock('2026-11-25 10:00:02 UTC');
    }

    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
        date_default_timezone_set($this->timezone);
    }

    public function testDeletingOneReportPreservesJobAndOtherReports(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'success');
        $runner = $this->createRunner($manager);
        $runner->execute();
        $runner->execute([$job]);
        [$first, $second] = $job->getReports()->toArray();
        $jobId = $job->getId();
        $manager->clear();

        $manager->remove($manager->find(Mh1CronJobReport::class, $first->getId()));
        $manager->flush();
        $manager->clear();

        $job = $manager->find(Mh1CronJob::class, $jobId);
        self::assertInstanceOf(Mh1CronJob::class, $job);
        self::assertCount(1, $job->getReports());
        self::assertSame($second->getId(), $job->getReports()->first()->getId());

        $manager->remove($job);
        $manager->flush();
        self::assertSame(0, $manager->getRepository(Mh1CronJobReport::class)->count([]));
        self::assertSame(0, $manager->getRepository(Mh1CronJobSlot::class)->count([]));
    }

    public function testIndependentRunnersClaimAScheduledMinuteOnlyOnce(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'mh1-cron-');
        self::assertIsString($database);
        $manager = null;
        $otherManager = null;
        try {
            $manager = $this->createEntityManager($database);
            $this->createSchema($manager);
            $this->addJob($manager, 'success');

            $otherManager = $this->createEntityManager($database);
            $otherRunner = $this->createRunner($otherManager);
            $runner = $this->createRunner($manager, static function () use ($otherRunner): void {
                $otherRunner->execute();
            });
            $runner->execute();
            $this->setClock('2026-11-25 10:00:59 UTC');
            $otherRunner->execute();
            self::assertSame(1, $manager->getRepository(Mh1CronJobReport::class)->count([]));

            $manager->clear();
            $report = $manager->getRepository(Mh1CronJobReport::class)->findOneBy([]);
            $manager->remove($report);
            $manager->flush();
            $otherRunner->execute();
            self::assertSame(0, $manager->getRepository(Mh1CronJobReport::class)->count([]));

            $this->setClock('2026-11-25 10:01:00 UTC');
            $otherRunner->execute();
            self::assertSame(1, $manager->getRepository(Mh1CronJobReport::class)->count([]));
            self::assertSame(2, $manager->getRepository(Mh1CronJobSlot::class)->count([]));
        } finally {
            $otherManager?->getConnection()->close();
            $manager?->getConnection()->close();
            unlink($database);
        }
    }

    public function testRunnerContinuesWhenAScheduledJobIsDeletedBeforeItsClaim(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'mh1-cron-');
        self::assertIsString($database);
        $manager = null;
        $otherManager = null;
        try {
            $manager = $this->createEntityManager($database);
            $this->createSchema($manager);
            $removed = $this->addJob($manager, 'removed');
            $remaining = $this->addJob($manager, 'success');
            $otherManager = $this->createEntityManager($database);

            $runner = new class (
                new DoctrineCronJobLogService($manager),
                $manager,
                __DIR__ . '/console-fixture.php',
                10,
                'UTC',
                PHP_BINARY
            ) extends DoctrineCronJobService {
                public Closure $afterSelection;

                public function getScheduledJobs(?DateTimeInterface $scheduleDateTime = null): array
                {
                    $jobs = parent::getScheduledJobs($scheduleDateTime);
                    ($this->afterSelection)();

                    return $jobs;
                }
            };
            $runner->afterSelection = static function () use ($otherManager, $removed): void {
                $job = $otherManager->find(Mh1CronJob::class, $removed->getId());
                self::assertInstanceOf(Mh1CronJob::class, $job);
                $otherManager->remove($job);
                $otherManager->flush();
            };
            $runner->execute();

            self::assertSame([], $runner->getFailures());
            self::assertSame(1, $manager->getRepository(Mh1CronJobSlot::class)->count([]));
            self::assertCount(1, $remaining->getReports());
            self::assertSame(Command::SUCCESS, $remaining->getReports()->first()->getExitCode());
        } finally {
            $otherManager?->getConnection()->close();
            $manager?->getConnection()->close();
            unlink($database);
        }
    }

    public function testLegacyReportsCatchUpOnlyTheLatestMissedMinute(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'success', '0 * * * *');
        $job->setExecuteStalled(true);
        $previousReport = new Mh1CronJobReport($job, new DateTime('2026-11-25 07:00:00 UTC'));
        $previousReport->setEndTime(new DateTime('2026-11-25 07:00:10 UTC'));
        $manager->persist($previousReport);
        $manager->flush();

        $runner = $this->createRunner($manager);
        $this->setClock('2026-11-25 10:15:00 UTC');
        $runner->execute();
        $this->setClock('2026-11-25 10:30:00 UTC');
        $runner->execute();
        $this->setClock('2026-11-25 10:00:45 UTC');
        $runner->execute();

        self::assertSame(2, $manager->getRepository(Mh1CronJobReport::class)->count([]));
        $slot = $manager->getRepository(Mh1CronJobSlot::class)->findOneBy([]);
        $scheduledTime = new DateTimeImmutable('2026-11-25 10:00:00 UTC');
        self::assertSame(intdiv($scheduledTime->getTimestamp(), 60), $slot->scheduledMinute);
    }

    public function testCatchUpUsesTheReservedMinuteAfterALongRunAndTimezoneChange(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'success', '0 * * * *');
        $job->setExecuteStalled(true);
        $manager->flush();

        $runner = $this->createRunner($manager, function (): void {
            $this->setClock('2026-11-25 11:05:00 UTC');
        }, 'Europe/Berlin');
        $runner->execute();
        self::assertSame('2026-11-25T11:05:00+00:00', $job->getReports()->first()->getEndTime()->format(DATE_ATOM));

        date_default_timezone_set('Asia/Tokyo');
        $manager->clear();
        $this->setClock('2026-11-25 11:15:00 UTC');
        $this->createRunner($manager, timezone: 'Europe/Berlin')->execute();

        $job = $manager->find(Mh1CronJob::class, $job->getId());
        self::assertCount(2, $job->getReports());
        $scheduledTime = new DateTimeImmutable('2026-11-25 11:00:00 UTC');
        $slot = $manager->getRepository(Mh1CronJobSlot::class)->findOneBy([
            'scheduledMinute' => intdiv($scheduledTime->getTimestamp(), 60),
        ]);
        self::assertInstanceOf(Mh1CronJobSlot::class, $slot);
    }

    public function testCatchUpRequiresHistoryAndOptIn(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $newJob = $this->addJob($manager, 'success', '0 * * * *');
        $newJob->setExecuteStalled(true);
        $regularOnly = $this->addJob($manager, 'success', '0 * * * *');
        $pastStart = new DateTimeImmutable('2026-11-25 08:00:00 UTC');
        $report = new Mh1CronJobReport($regularOnly, $pastStart);
        $report->setEndTime($pastStart);
        $manager->persist($report);
        $manager->flush();

        $runner = $this->createRunner($manager);
        $this->setClock('2026-11-25 10:15:00 UTC');
        $runner->execute();
        self::assertSame(0, $manager->getRepository(Mh1CronJobSlot::class)->count([]));

        $this->setClock('2026-11-25 11:00:00 UTC');
        $runner->execute();
        self::assertSame(2, $manager->getRepository(Mh1CronJobSlot::class)->count([]));
        self::assertCount(1, $newJob->getReports());
        self::assertCount(2, $regularOnly->getReports());
    }

    public function testCatchUpSkipsTheMissingSpringForwardMinute(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'success', '30 2 * * *');
        $job->setExecuteStalled(true);
        $manager->flush();
        $runner = $this->createRunner($manager, timezone: 'Europe/Berlin');

        $this->setClock('2026-03-28 01:30:00 UTC');
        $runner->execute();
        $this->setClock('2026-03-29 02:15:00 UTC');
        $runner->execute();
        self::assertCount(1, $job->getReports());

        $this->setClock('2026-03-30 00:45:00 UTC');
        $runner->execute();
        self::assertCount(2, $job->getReports());
    }

    public function testCatchUpFindsTheFirstOccurrenceDuringTheRepeatedHour(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'success', '30 2 * * *');
        $job->setExecuteStalled(true);
        $manager->flush();
        $runner = $this->createRunner($manager, timezone: 'Europe/Berlin');

        $this->setClock('2026-10-24 00:30:00 UTC');
        $runner->execute();
        $this->setClock('2026-10-25T02:15:00+01:00');
        $runner->execute();

        $firstOccurrence = new DateTimeImmutable('2026-10-25T02:30:00+02:00');
        $firstSlot = $manager->getRepository(Mh1CronJobSlot::class)->findOneBy([
            'scheduledMinute' => intdiv($firstOccurrence->getTimestamp(), 60),
        ]);
        self::assertInstanceOf(Mh1CronJobSlot::class, $firstSlot);

        $this->setClock('2026-10-25T02:30:00+01:00');
        $runner->execute();
        self::assertCount(3, $job->getReports());
    }

    public function testCatchUpInAFixedOffsetTimezone(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'success', '15 6 * * *');
        $job->setExecuteStalled(true);
        $manager->flush();
        $runner = $this->createRunner($manager, timezone: '+05:45');

        $this->setClock('2026-11-25 00:30:02 UTC');
        $runner->execute();
        $this->setClock('2026-11-27 00:45:00 UTC');
        $runner->execute();

        $latest = $manager->getRepository(Mh1CronJobSlot::class)->findLatestScheduledMinute($job);
        $scheduledTime = new DateTimeImmutable('2026-11-27 00:30:00 UTC');
        self::assertSame(intdiv($scheduledTime->getTimestamp(), 60), $latest);
        self::assertCount(2, $job->getReports());
    }

    public function testReportsRoundTripUtcWhenThePhpTimezoneChanges(): void
    {
        date_default_timezone_set('Pacific/Auckland');
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'success');
        $start = new DateTime('2026-10-25T02:10:00+02:00');
        $end = new DateTimeImmutable('2026-10-25T02:10:30+01:00');
        $report = new Mh1CronJobReport($job, $start);
        $report->setEndTime($end);
        $manager->persist($report);
        $manager->flush();

        $metadata = $manager->getClassMetadata(Mh1CronJobReport::class);
        $row = $manager->getConnection()->fetchAssociative('SELECT * FROM ' . $metadata->getTableName());
        self::assertSame('2026-10-25 00:10:00', $row[$metadata->getColumnName('startTime')]);
        self::assertSame('2026-10-25 01:10:30', $row[$metadata->getColumnName('endTime')]);
        self::assertSame('+02:00', $start->format('P'));

        date_default_timezone_set('America/New_York');
        $manager->clear();
        $loaded = $manager->find(Mh1CronJobReport::class, $report->getId());
        self::assertSame($start->getTimestamp(), $loaded->getStartTime()->getTimestamp());
        self::assertSame($end->getTimestamp(), $loaded->getEndTime()->getTimestamp());
        self::assertSame('UTC', $loaded->getStartTime()->getTimezone()->getName());
        self::assertSame('UTC', $loaded->getEndTime()->getTimezone()->getName());
    }

    public function testRepeatedLocalMinuteHasTwoDistinctSlots(): void
    {
        date_default_timezone_set('Europe/Berlin');
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'success', '30 2 * * *');
        $runner = $this->createRunner($manager, timezone: 'Europe/Berlin');

        $this->setClock('2026-10-25T02:30:05+02:00');
        $runner->execute();
        $this->setClock('2026-10-25T02:30:05+01:00');
        $runner->execute();

        self::assertCount(2, $job->getReports());
        self::assertSame(2, $manager->getRepository(Mh1CronJobSlot::class)->count([]));
    }

    public function testFailuresAndTimeoutsLeaveTheOtherJobsRunning(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $invalid = $this->addJob($manager, 'invalid', 'invalid cron');
        $impossible = $this->addJob($manager, 'impossible', '0 0 31 2 *');
        $impossible->setExecuteStalled(true);
        $previousReport = new Mh1CronJobReport($impossible, new DateTime('2026-11-25 09:00:00 UTC'));
        $previousReport->setEndTime(new DateTime('2026-11-25 09:00:10 UTC'));
        $manager->persist($previousReport);
        $manager->flush();
        $startup = $this->addJob($manager, 'startup');
        $timeout = $this->addJob($manager, 'timeout');
        $failure = $this->addJob($manager, 'failure');
        $success = $this->addJob($manager, 'success');
        $tester = new CommandTester(new CronRunCommand($this->createRunner($manager)));
        $status = $tester->execute(['--strict' => true], ['capture_stderr_separately' => true]);
        self::assertSame(Command::FAILURE, $status);

        self::assertCount(0, $invalid->getReports());
        self::assertSame(Command::FAILURE, $startup->getReports()->first()->getExitCode());
        $timeoutReport = $timeout->getReports()->first();
        self::assertSame(DoctrineCronJobService::TIMEOUT_EXIT_CODE, $timeoutReport->getExitCode());
        self::assertNotNull($timeoutReport->getEndTime());
        self::assertStringContainsString('before timeout', $timeoutReport->getOutput());
        self::assertStringNotContainsString('after timeout', $timeoutReport->getOutput());
        self::assertSame(7, $failure->getReports()->first()->getExitCode());
        self::assertStringContainsString('child error', $failure->getReports()->first()->getOutput());
        self::assertSame(Command::SUCCESS, $success->getReports()->first()->getExitCode());

        $errors = $tester->getErrorOutput();
        self::assertStringContainsString('invalid cron', $errors);
        self::assertStringContainsString('impossible: Impossible CRON expression', $errors);
        self::assertStringContainsString('startup: process creation failed', $errors);
        self::assertStringContainsString('timeout:', $errors);
        self::assertStringContainsString('failure: Process exited with code 7.', $errors);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertCount(1, $timeout->getReports());
        self::assertCount(1, $failure->getReports());
    }

    public function testConfiguredTimeoutIsCheckedBeforeTheRegularPollingInterval(): void
    {
        $manager = $this->createEntityManager();
        $this->createSchema($manager);
        $job = $this->addJob($manager, 'timeout');
        $runner = new DoctrineCronJobService(
            new DoctrineCronJobLogService($manager),
            $manager,
            __DIR__ . '/console-fixture.php',
            4000,
            'UTC',
            PHP_BINARY
        );
        $runner->setProcessTimeout(1);
        $tester = new CommandTester(new CronRunCommand($runner));

        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true]));
        self::assertCount(1, $job->getReports());
        $report = $job->getReports()->first();
        self::assertSame(DoctrineCronJobService::TIMEOUT_EXIT_CODE, $report->getExitCode());
        self::assertStringContainsString('before timeout', $report->getOutput());
        self::assertStringNotContainsString('after timeout', $report->getOutput());
    }

    private function createSchema(EntityManager $manager): void
    {
        (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    }

    private function addJob(EntityManager $manager, string $command, string $schedule = '* * * * *'): Mh1CronJob
    {
        $job = (new Mh1CronJob())->setCommand($command)->setTitle($command)
            ->setEnabled(true)->setSchedule($schedule)->setExecuteStalled(false);
        $manager->persist($job);
        $manager->flush();

        return $job;
    }

    private function createRunner(
        EntityManager $manager,
        ?Closure $beforeStart = null,
        string $timezone = 'UTC'
    ): DoctrineCronJobService {
        return new class (
            new DoctrineCronJobLogService($manager),
            $manager,
            __DIR__ . '/console-fixture.php',
            10,
            $timezone,
            PHP_BINARY,
            $beforeStart
        ) extends DoctrineCronJobService {
            public function __construct(
                DoctrineCronJobLogService $logger,
                EntityManager $manager,
                string $console,
                int $interval,
                string $timezone,
                string $php,
                private ?Closure $beforeStart
            ) {
                parent::__construct($logger, $manager, $console, $interval, $timezone, $php);
            }

            public function createProcess(CronJobInterface $job): Process
            {
                if ($this->beforeStart !== null) {
                    ($this->beforeStart)();
                }
                if ($job->getCommand() === 'startup') {
                    throw new RuntimeException('process creation failed');
                }

                $process = parent::createProcess($job);
                if ($job->getCommand() === 'timeout') {
                    $process->setTimeout(0.5);
                }

                return $process;
            }
        };
    }

    private function setClock(string $time): void
    {
        ClockMock::withClockMock((new DateTimeImmutable($time))->getTimestamp());
    }
}
