<?php
declare(strict_types=1);

namespace MH1\CronBundle\Helper;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class DateTimeHelper
{
    /**
     * @return DateTime
     */
    public static function getUTCDateTime(): DateTime
    {
        return (new DateTime('@' . time()))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return DateTimeImmutable
     */
    public static function getUTCDateTimeImmutable(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . time()))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function toUTC(DateTimeInterface $time): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function getEpochMinute(DateTimeInterface $time): int
    {
        return (int) floor($time->getTimestamp() / 60);
    }
}
