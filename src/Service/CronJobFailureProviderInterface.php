<?php
declare(strict_types=1);

namespace MH1\CronBundle\Service;

interface CronJobFailureProviderInterface
{
    /** @return list<string> */
    public function getFailures(): array;
}
