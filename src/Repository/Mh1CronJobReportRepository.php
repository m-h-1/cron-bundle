<?php
declare(strict_types=1);

namespace MH1\CronBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use MH1\CronBundle\Entity\Mh1CronJob;
use MH1\CronBundle\Entity\Mh1CronJobReport;

/**
 * @extends EntityRepository<Mh1CronJobReport>
 */
class Mh1CronJobReportRepository extends EntityRepository
{
    /**
     * @param Mh1CronJob $cronJob
     *
     * @return Mh1CronJobReport|null
     * @throws NonUniqueResultException
     */
    public function findLastExecutionByJob(Mh1CronJob $cronJob): ?Mh1CronJobReport
    {
        return $this->findLastCompletedReport($cronJob, 'report.endTime DESC');
    }

    public function findLastExecutionByStart(Mh1CronJob $cronJob): ?Mh1CronJobReport
    {
        return $this->findLastCompletedReport($cronJob, 'report.startTime DESC');
    }

    /** @param 'report.startTime DESC'|'report.endTime DESC' $orderBy */
    private function findLastCompletedReport(Mh1CronJob $cronJob, string $orderBy): ?Mh1CronJobReport
    {
        return $this->getEntityManager()->createQuery(
            'SELECT report FROM ' . Mh1CronJobReport::class . ' report'
            . ' WHERE report.cronJob = :cronJob AND report.endTime IS NOT NULL'
            . ' ORDER BY ' . $orderBy
        )->setParameter('cronJob', $cronJob)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }
}
