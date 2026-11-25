<?php
declare(strict_types=1);

namespace MH1\CronBundle\Service;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use MH1\CronBundle\Entity\Mh1CronJob;
use MH1\CronBundle\Entity\Mh1CronJobReport;
use MH1\CronBundle\Entity\Mh1CronJobSlot;
use MH1\CronBundle\Helper\DateTimeHelper;
use MH1\CronBundle\Model\CronJobInterface;
use MH1\CronBundle\Repository\Mh1CronJobReportRepository;
use MH1\CronBundle\Repository\Mh1CronJobRepository;
use MH1\CronBundle\Repository\Mh1CronJobSlotRepository;
use RuntimeException;

class DoctrineCronJobService extends AbstractCronJobService
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @inheritDoc
     */
    public function __construct(
        CronJobLogServiceInterface $cronJobLogService,
        EntityManagerInterface     $entityManager,
        string                     $consolePath,
        int                        $checkInterval,
        ?string                    $executionTimeZone = null,
        ?string                    $phpExecutablePath = null
    ) {
        $this->entityManager = $entityManager;
        parent::__construct($cronJobLogService, $consolePath, $checkInterval, $executionTimeZone, $phpExecutablePath);
    }

    /**
     * @inheritDoc
     */
    public function getEnabledJobs(): array
    {
        /** @var Mh1CronJobRepository $repository */
        $repository = $this->entityManager->getRepository(Mh1CronJob::class);
        return $repository->findEnabledJobs();
    }

    protected function getScheduledTime(CronJobInterface $job, DateTimeInterface $time): ?DateTimeInterface
    {
        $scheduledTime = parent::getScheduledTime($job, $time);
        if ($scheduledTime !== null) {
            return $scheduledTime;
        }

        if (!$job instanceof Mh1CronJob || !$job->isExecuteStalled()) {
            return null;
        }

        /** @var Mh1CronJobSlotRepository $slotRepository */
        $slotRepository = $this->entityManager->getRepository(Mh1CronJobSlot::class);
        $lastScheduledMinute = $slotRepository->findLatestScheduledMinute($job);
        if ($lastScheduledMinute === null) {
            // Older installations only have reports. Their start time is the best available reference.
            /** @var Mh1CronJobReportRepository $reportRepository */
            $reportRepository = $this->entityManager->getRepository(Mh1CronJobReport::class);
            $lastStart = $reportRepository->findLastExecutionByStart($job)?->getStartTime();
            if ($lastStart === null) {
                return null;
            }

            $lastScheduledMinute = DateTimeHelper::getEpochMinute($lastStart);
        }

        $cron = new CronExpression($job->getSchedule());
        try {
            return $this->getLatestMissedTime($cron, $lastScheduledMinute, $time);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }

    private function getLatestMissedTime(
        CronExpression $cron,
        int $lastScheduledMinute,
        DateTimeInterface $time
    ): ?DateTimeImmutable {
        $start = ($lastScheduledMinute + 1) * 60;
        $end = DateTimeHelper::getEpochMinute($time) * 60 - 1;
        if ($end < $start) {
            return null;
        }

        $timezone = new DateTimeZone($this->executionTimeZone);
        $periods = $timezone->getTransitions($start, $end + 1);
        if (!$periods) {
            $periods = [['ts' => $start, 'offset' => $timezone->getOffset($time)]];
        }

        // Search each fixed offset separately to account for skipped and repeated local minutes.
        foreach (array_reverse($periods) as $period) {
            $localEnd = new DateTimeImmutable('@' . ($end + $period['offset']));
            $candidate = $cron->getPreviousRunDate($localEnd, 0, true, 'UTC')->getTimestamp() - $period['offset'];
            if ($candidate >= $period['ts'] && $candidate <= $end) {
                return DateTimeHelper::toUTC(new DateTimeImmutable('@' . $candidate));
            }

            $end = $period['ts'] - 1;
        }

        return null;
    }

    protected function claimScheduledJob(CronJobInterface $job, DateTimeInterface $scheduleTime): bool
    {
        if (!$job instanceof Mh1CronJob) {
            return parent::claimScheduledJob($job, $scheduleTime);
        }

        $scheduledTime = $this->getScheduledTime($job, $scheduleTime);
        if ($scheduledTime === null) {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        // A rolled-back reservation could allow another runner to start the same child process.
        if (!$connection->isAutoCommit() || $connection->isTransactionActive()) {
            throw new RuntimeException('Scheduled slots must be committed before the child process starts.');
        }

        try {
            $connection->insert(Mh1CronJobSlot::TABLE, [
                'cron_job_id' => $job->getId(),
                'scheduled_minute' => DateTimeHelper::getEpochMinute($scheduledTime),
            ], ['uuid', Types::INTEGER]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
