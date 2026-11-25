<?php
declare(strict_types=1);

namespace MH1\CronBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use MH1\CronBundle\Repository\Mh1CronJobSlotRepository;

#[ORM\Entity(repositoryClass: Mh1CronJobSlotRepository::class, readOnly: true)]
#[ORM\Table(name: self::TABLE)]
class Mh1CronJobSlot
{
    public const TABLE = 'mh1_cron_job_slot';

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Mh1CronJob::class)]
    #[ORM\JoinColumn(name: 'cron_job_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    public Mh1CronJob $cronJob;

    #[ORM\Id]
    #[ORM\Column(name: 'scheduled_minute', type: 'integer')]
    public int $scheduledMinute;
}
