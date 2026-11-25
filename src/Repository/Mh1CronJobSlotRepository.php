<?php
declare(strict_types=1);

namespace MH1\CronBundle\Repository;

use Doctrine\ORM\EntityRepository;
use MH1\CronBundle\Entity\Mh1CronJob;
use MH1\CronBundle\Entity\Mh1CronJobSlot;

/** @extends EntityRepository<Mh1CronJobSlot> */
class Mh1CronJobSlotRepository extends EntityRepository
{
    public function findLatestScheduledMinute(Mh1CronJob $job): ?int
    {
        $minute = $this->createQueryBuilder('slot')
            ->select('MAX(slot.scheduledMinute)')
            ->where('slot.cronJob = :job')
            ->setParameter('job', $job)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($minute) ? (int) $minute : null;
    }
}
