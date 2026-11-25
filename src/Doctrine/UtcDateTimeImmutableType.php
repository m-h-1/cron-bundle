<?php
declare(strict_types=1);

namespace MH1\CronBundle\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use MH1\CronBundle\Helper\DateTimeHelper;

class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const NAME = 'mh1_cron_utc_datetime_immutable';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            $value = DateTimeHelper::toUTC($value);
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $utc = new DateTimeZone('UTC');
            $parsed = DateTimeImmutable::createFromFormat('!' . $platform->getDateTimeFormatString(), $value, $utc);
            $value = $parsed === false ? new DateTimeImmutable($value, $utc) : $parsed;
        }

        return DateTimeHelper::toUTC(parent::convertToPHPValue($value, $platform));
    }
}
