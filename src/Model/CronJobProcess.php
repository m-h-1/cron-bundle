<?php
declare(strict_types=1);

namespace MH1\CronBundle\Model;

use Symfony\Component\Process\Process;

class CronJobProcess
{
    private Process $process;

    private CronJobReportInterface $report;

    public function __construct(Process $process, CronJobReportInterface $log)
    {
        $this->process = $process;
        $this->report = $log;
    }

    public function getProcess(): Process
    {
        return $this->process;
    }

    public function getReport(): CronJobReportInterface
    {
        return $this->report;
    }
}
