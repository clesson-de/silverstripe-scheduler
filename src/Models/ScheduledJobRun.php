<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Models;

use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Permission;

/**
 * A single execution of a ScheduledJob. One row is written for every
 * subprocess started by the runner, plus updated on completion/timeout.
 *
 * @property string $Status
 * @property DBDatetime $StartedAt
 * @property DBDatetime $FinishedAt
 * @property int $DurationMs
 * @property int $ExitCode
 * @property string $Output
 * @property string $ErrorOutput
 * @property int $JobID
 * @property int $ConfigID
 * @method ScheduledJob Job()
 * @method SchedulerConfig Config()
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Models
 */
class ScheduledJobRun extends DataObject
{
    /**
     * @inheritdoc
     */
    private static string $table_name = 'Scheduler_JobRun';

    /**
     * @inheritdoc
     */
    private static string $default_sort = 'Created DESC';

    /**
     * @inheritdoc
     */
    private static array $db = [
        'Status' => 'Varchar(20)',
        'StartedAt' => 'Datetime',
        'FinishedAt' => 'Datetime',
        'DurationMs' => 'Int',
        'ExitCode' => 'Int',
        'Output' => 'Text',
        'ErrorOutput' => 'Text',
    ];

    /**
     * @inheritdoc
     */
    private static array $has_one = [
        'Job' => ScheduledJob::class,
        'Config' => SchedulerConfig::class,
    ];

    /**
     * @inheritdoc
     */
    private static array $summary_fields = [
        'Created',
        'Job.Title',
        'Status',
        'DurationMs',
        'ExitCode',
    ];

    /**
     * @inheritdoc
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Status']      = _t(__CLASS__ . '.STATUS', 'Status');
        $labels['StartedAt']   = _t(__CLASS__ . '.STARTED_AT', 'Started');
        $labels['FinishedAt']  = _t(__CLASS__ . '.FINISHED_AT', 'Finished');
        $labels['DurationMs']  = _t(__CLASS__ . '.DURATION_MS', 'Duration (ms)');
        $labels['ExitCode']    = _t(__CLASS__ . '.EXIT_CODE', 'Exit code');
        $labels['Output']      = _t(__CLASS__ . '.OUTPUT', 'Output');
        $labels['ErrorOutput'] = _t(__CLASS__ . '.ERROR_OUTPUT', 'Error output');
        $labels['Job']         = _t(__CLASS__ . '.JOB', 'Job');
        $labels['Job.Title']   = _t(__CLASS__ . '.JOB', 'Job');
        return $labels;
    }

    /**
     * Returns the translated label for the current status.
     *
     * @return string
     */
    public function getStatusLabel(): string
    {
        return JobStatus::label((string) $this->Status);
    }

    /**
     * @inheritdoc
     */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /**
     * Runs are written by the runner, never by CMS users.
     *
     * @inheritdoc
     */
    public function canEdit($member = null): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    /**
     * Admins may delete history entries to keep the table small.
     *
     * @inheritdoc
     */
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}
