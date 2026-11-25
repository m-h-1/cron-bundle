<?php
declare(strict_types=1);

namespace MH1\CronBundle\Tests\Integration;

use Doctrine\ORM\Tools\SchemaValidator;
use MH1\CronBundle\Entity\Mh1CronJob;
use MH1\CronBundle\Entity\Mh1CronJobReport;
use MH1\CronBundle\Entity\Mh1CronJobSlot;

class DoctrineMappingTest extends DoctrineTestCase
{
    public function testJobAndReportMappingsAreConsistent(): void
    {
        $entityManager = $this->createEntityManager();
        $validator = new SchemaValidator($entityManager);

        self::assertSame([], $validator->validateClass($entityManager->getClassMetadata(Mh1CronJob::class)));
        self::assertSame([], $validator->validateClass($entityManager->getClassMetadata(Mh1CronJobReport::class)));
        self::assertSame([], $validator->validateClass($entityManager->getClassMetadata(Mh1CronJobSlot::class)));
    }
}
