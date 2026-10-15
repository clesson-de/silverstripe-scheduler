<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Services;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Helper for the nine "bundeseinheitliche" (nationwide) German public holidays.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Services
 */
final class GermanHolidays
{
    /**
     * Returns true if the given date is a nationwide German public holiday.
     *
     * @param DateTimeInterface $date
     * @return bool
     */
    public static function isHoliday(DateTimeInterface $date): bool
    {
        $ymd = $date->format('Y-m-d');
        $year = (int) $date->format('Y');
        return in_array($ymd, self::getHolidays($year), true);
    }

    /**
     * Returns the list of nationwide German holidays for the given year,
     * formatted as Y-m-d strings.
     *
     * @param int $year
     * @return list<string>
     */
    public static function getHolidays(int $year): array
    {
        $easter = self::easterSunday($year);
        $fmt = static fn (DateTimeImmutable $d, string $modify = ''): string =>
            ($modify === '' ? $d : $d->modify($modify))->format('Y-m-d');

        return [
            sprintf('%d-01-01', $year), // Neujahr
            $fmt($easter, '-2 days'),   // Karfreitag
            $fmt($easter, '+1 day'),    // Ostermontag
            sprintf('%d-05-01', $year), // Tag der Arbeit
            $fmt($easter, '+39 days'),  // Christi Himmelfahrt
            $fmt($easter, '+50 days'),  // Pfingstmontag
            sprintf('%d-10-03', $year), // Tag der Deutschen Einheit
            sprintf('%d-12-25', $year), // 1. Weihnachtsfeiertag
            sprintf('%d-12-26', $year), // 2. Weihnachtsfeiertag
        ];
    }

    /**
     * Gauss's Easter algorithm — computes Easter Sunday for the Gregorian
     * calendar without relying on the optional PHP calendar extension.
     *
     * @param int $year
     * @return DateTimeImmutable
     */
    private static function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
