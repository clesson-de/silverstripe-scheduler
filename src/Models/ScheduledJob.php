<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Models;

use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use Clesson\Silverstripe\Scheduler\Forms\CronScheduleField;
use Clesson\Silverstripe\Scheduler\Forms\GridFieldConfig_RunsInScheduledJob;
use Clesson\Silverstripe\Scheduler\Services\GermanHolidays;
use Cron\CronExpression;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Permission;

/**
 * A single scheduled job. Represents one BuildTask that is executed on a
 * cron-like schedule by SchedulerRunTask.
 *
 * @property string $Description
 * @property string $TaskClass
 * @property string $CronExpression
 * @property bool $Enabled
 * @property bool $SkipOnHolidays
 * @property int $MaxRuntimeSeconds
 * @property int $Sort
 * @property DBDatetime $LastRunAt
 * @property string $LastStatus
 * @property string $LastMessage
 * @property int $LastDurationMs
 * @property DBDatetime $RunningSince
 * @property int $ConfigID
 * @method SchedulerConfig Config()
 * @method HasManyList|ScheduledJobRun[] Runs()
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Models
 */
class ScheduledJob extends DataObject
{
    /**
     * @inheritdoc
     */
    private static string $table_name = 'Scheduler_Job';

    /**
     * @inheritdoc
     */
    private static string $default_sort = 'Sort ASC, ID ASC';

    /**
     * @inheritdoc
     */
    private static array $db = [
        'Description' => 'Text',
        'TaskClass' => 'Varchar(255)',
        'CronExpression' => 'Varchar(100)',
        'Enabled' => 'Boolean',
        'SkipOnHolidays' => 'Boolean',
        'MaxRuntimeSeconds' => 'Int',
        'Sort' => 'Int',
        'LastRunAt' => 'Datetime',
        'LastStatus' => 'Varchar(20)',
        'LastMessage' => 'Text',
        'LastDurationMs' => 'Int',
        'RunningSince' => 'Datetime',
    ];

    /**
     * @inheritdoc
     */
    private static array $has_one = [
        'Config' => SchedulerConfig::class,
    ];

    /**
     * @inheritdoc
     */
    private static array $has_many = [
        'Runs' => ScheduledJobRun::class . '.Job',
    ];

    /**
     * @inheritdoc
     */
    private static array $defaults = [
        'Enabled' => true,
        'CronExpression' => '*/15 * * * *',
        'MaxRuntimeSeconds' => 300,
    ];

    /**
     * @inheritdoc
     */
    private static array $summary_fields = [
        'TaskClass',
        'CronExpression',
        'Enabled.Nice',
        'LastRunAt.Nice',
        'LastStatus',
    ];

    /**
     * @inheritdoc
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Description']      = _t(__CLASS__ . '.DESCRIPTION', 'Description');
        $labels['TaskClass']        = _t(__CLASS__ . '.TASK_CLASS', 'Task');
        $labels['CronExpression']   = _t(__CLASS__ . '.CRON_EXPRESSION', 'Cron expression');
        $labels['Enabled']          = _t(__CLASS__ . '.ENABLED', 'Enabled');
        $labels['SkipOnHolidays']   = _t(__CLASS__ . '.SKIP_ON_HOLIDAYS', 'Skip on public holidays');
        $labels['MaxRuntimeSeconds'] = _t(__CLASS__ . '.MAX_RUNTIME_SECONDS', 'Max runtime (seconds)');
        $labels['LastRunAt']        = _t(__CLASS__ . '.LAST_RUN_AT', 'Last run');
        $labels['LastStatus']       = _t(__CLASS__ . '.LAST_STATUS', 'Last status');
        $labels['LastMessage']      = _t(__CLASS__ . '.LAST_MESSAGE', 'Last message');
        $labels['LastDurationMs']   = _t(__CLASS__ . '.LAST_DURATION_MS', 'Last duration (ms)');
        $labels['RunningSince']     = _t(__CLASS__ . '.RUNNING_SINCE', 'Running since');
        $labels['Runs']             = _t(__CLASS__ . '.RUNS', 'History');
        return $labels;
    }

    /**
     * @inheritdoc
     */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName([
            'Description', 'TaskClass', 'CronExpression', 'Enabled', 'SkipOnHolidays',
            'MaxRuntimeSeconds', 'Sort',
            'LastRunAt', 'LastStatus', 'LastMessage', 'LastDurationMs',
            'RunningSince', 'ConfigID', 'Runs',
        ]);

        $fields->addFieldsToTab('Root.Main', [
            DropdownField::create(
                'TaskClass',
                $this->fieldLabel('TaskClass'),
                $this->getTaskClassOptions()
            )->setEmptyString('— ' . _t(__CLASS__ . '.SELECT_TASK', 'Select task') . ' —'),
            CronScheduleField::create('CronExpression', $this->fieldLabel('CronExpression')),
            NumericField::create('MaxRuntimeSeconds', $this->fieldLabel('MaxRuntimeSeconds'))
                ->setDescription(_t(
                    __CLASS__ . '.MAX_RUNTIME_DESC',
                    'Subprocess is killed after this many seconds. 0 = unlimited.'
                )),
            CheckboxField::create('Enabled', $this->fieldLabel('Enabled')),
            CheckboxField::create('SkipOnHolidays', $this->fieldLabel('SkipOnHolidays'))
                ->setDescription(_t(
                    __CLASS__ . '.SKIP_ON_HOLIDAYS_DESC',
                    'When enabled, this job is skipped on nationwide German public holidays.'
                )),
            TextareaField::create('Description', $this->fieldLabel('Description'))
                ->setRows(3)
                ->setDescription(_t(
                    __CLASS__ . '.DESCRIPTION_DESC',
                    'Optional note about this job.'
                )),
        ]);

        if ($this->isInDB()) {
            $fields->addFieldsToTab('Root.' . _t(__CLASS__ . '.EXECUTION_TAB', 'Execution'), [
                ReadonlyField::create('LastRunAt', $this->fieldLabel('LastRunAt')),
                ReadonlyField::create('LastStatus', $this->fieldLabel('LastStatus'))
                    ->setValue(JobStatus::label((string) $this->LastStatus)),
                ReadonlyField::create('LastDurationMs', $this->fieldLabel('LastDurationMs')),
                ReadonlyField::create('LastMessage', $this->fieldLabel('LastMessage')),
                ReadonlyField::create('RunningSince', $this->fieldLabel('RunningSince')),
                LiteralField::create(
                    'NextRunInfo',
                    '<p><strong>' . _t(__CLASS__ . '.NEXT_RUN', 'Next scheduled run') . ':</strong> '
                    . htmlspecialchars($this->getNextRunDescription()) . '</p>'
                ),
            ]);

            /** @var GridField $runsField */
            $runsField = GridField::create(
                'Runs',
                '',
                $this->Runs()->sort('Created DESC')->limit(100),
                GridFieldConfig_RunsInScheduledJob::create()
            );
            $fields->addFieldToTab('Root.' . $this->fieldLabel('Runs'), $runsField);
        }

        return $fields;
    }

    /**
     * Returns the display title used in the CMS. Derived from the selected
     * task class (its BuildTask title) since jobs don't have a title field.
     *
     * @return string
     */
    public function getTitle(): string
    {
        if ($this->TaskClass && class_exists($this->TaskClass)) {
            try {
                /** @var BuildTask $instance */
                $instance = \SilverStripe\Core\Injector\Injector::inst()->create($this->TaskClass);
                $label = $instance->getTitle();
                if ($label !== '') {
                    return $label;
                }
            } catch (\Throwable) {
                // fall through
            }
            return substr(strrchr($this->TaskClass, '\\') ?: $this->TaskClass, 1) ?: $this->TaskClass;
        }
        return _t(__CLASS__ . '.NEW_JOB', 'New scheduled job');
    }

    /**
     * Returns a list of executable task classes suitable for the TaskClass dropdown.
     *
     * @return array<string, string>
     */
    public function getTaskClassOptions(): array
    {
        $result = [];
        foreach (ClassInfo::subclassesFor(BuildTask::class, false) as $class) {
            // Skip this module's own runner/executor to avoid recursion.
            if ($class === \Clesson\Silverstripe\Scheduler\Tasks\SchedulerRunTask::class) {
                continue;
            }
            if ($class === \Clesson\Silverstripe\Scheduler\Tasks\ScheduledJobExecuteTask::class) {
                continue;
            }
            try {
                /** @var BuildTask $instance */
                $instance = \SilverStripe\Core\Injector\Injector::inst()->create($class);
                $title = $instance->getTitle();
            } catch (\Throwable) {
                $title = $class;
            }
            $result[$class] = $title !== '' ? $title : $class;
        }
        asort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    /**
     * Returns whether this job is due to run at the given moment based on
     * its cron expression and LastRunAt.
     *
     * @param \DateTimeInterface|null $now
     * @return bool
     */
    public function isDue(?\DateTimeInterface $now = null): bool
    {
        if (!$this->Enabled) {
            return false;
        }
        if (!$this->CronExpression) {
            return false;
        }
        if (!CronExpression::isValidExpression($this->CronExpression)) {
            return false;
        }

        $now = $now ?? new \DateTimeImmutable();
        if ($this->SkipOnHolidays && GermanHolidays::isHoliday($now)) {
            return false;
        }
        $cron = new CronExpression($this->CronExpression);

        // Consider a job "due" if the previous scheduled run is on or after LastRunAt.
        $previous = $cron->getPreviousRunDate($now, 0, true);
        if ($previous > $now) {
            return false;
        }

        $lastRun = $this->LastRunAt ? new \DateTimeImmutable((string) $this->LastRunAt) : null;
        if ($lastRun && $lastRun >= $previous) {
            return false;
        }

        return true;
    }

    /**
     * Returns the next scheduled run time as a human-readable string, or a
     * hint if the expression is invalid.
     *
     * @return string
     */
    public function getNextRunDescription(): string
    {
        if (!$this->CronExpression || !CronExpression::isValidExpression($this->CronExpression)) {
            return _t(__CLASS__ . '.INVALID_EXPRESSION', 'invalid cron expression');
        }
        try {
            $next = (new CronExpression($this->CronExpression))->getNextRunDate();
            return $next->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return _t(__CLASS__ . '.INVALID_EXPRESSION', 'invalid cron expression');
        }
    }

    /**
     * Validates the cron expression and task class before write.
     *
     * @inheritdoc
     */
    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if ($this->CronExpression && !CronExpression::isValidExpression($this->CronExpression)) {
            $result->addError(_t(
                __CLASS__ . '.ERROR_INVALID_CRON',
                'The cron expression is not valid.'
            ));
        }

        if ($this->TaskClass && !is_subclass_of($this->TaskClass, BuildTask::class)) {
            $result->addError(_t(
                __CLASS__ . '.ERROR_INVALID_TASK_CLASS',
                'The selected task class is not a valid BuildTask.'
            ));
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /**
     * @inheritdoc
     */
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /**
     * @inheritdoc
     */
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /**
     * @inheritdoc
     */
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}
