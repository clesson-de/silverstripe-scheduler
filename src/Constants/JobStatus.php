<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Constants;

/**
 * Available statuses for a ScheduledJobRun.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Constants
 */
class JobStatus
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const TIMEOUT = 'timeout';
    public const SKIPPED = 'skipped';

    /**
     * Returns all statuses as an associative array of value => translated label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::PENDING => _t(__CLASS__ . '.PENDING', 'Pending'),
            self::RUNNING => _t(__CLASS__ . '.RUNNING', 'Running'),
            self::SUCCESS => _t(__CLASS__ . '.SUCCESS', 'Success'),
            self::FAILURE => _t(__CLASS__ . '.FAILURE', 'Failure'),
            self::TIMEOUT => _t(__CLASS__ . '.TIMEOUT', 'Timeout'),
            self::SKIPPED => _t(__CLASS__ . '.SKIPPED', 'Skipped'),
        ];
    }

    /**
     * Returns the translated label for a given status value.
     *
     * @param mixed $value
     * @return string
     */
    public static function label(mixed $value): string
    {
        $options = self::options();
        return $options[$value] ?? (string) $value;
    }
}
