<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Models;

use Clesson\Silverstripe\Scheduler\Forms\GridFieldConfig_JobsInSchedulerAdmin;
use Clesson\Silverstripe\Scheduler\Forms\GridFieldConfig_RunsInSchedulerAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Central configuration record for the Scheduler module.
 *
 * Managed as a single record via SingleRecordAdmin.
 *
 * @method HasManyList|ScheduledJob[] Jobs()
 * @method HasManyList|ScheduledJobRun[] Runs()
 * @method ManyManyList|Member[] NotifyMembers()
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Models
 */
class SchedulerConfig extends DataObject
{
    /**
     * @inheritdoc
     */
    private static string $table_name = 'Scheduler_Config';

    /**
     * @inheritdoc
     */
    private static string $default_sort = 'Created DESC';

    /**
     * @inheritdoc
     */
    private static string $general_search_field = 'ID';

    /**
     * @inheritdoc
     */
    private static array $has_many = [
        'Jobs' => ScheduledJob::class . '.Config',
        'Runs' => ScheduledJobRun::class . '.Config',
    ];

    /**
     * @inheritdoc
     */
    private static array $many_many = [
        'NotifyMembers' => Member::class,
    ];

    /**
     * Returns the single configuration record, creating one if none exists.
     *
     * @return static
     */
    public static function currentRecord(): static
    {
        $record = static::get()->setUseCache(true)->first();
        if (!$record) {
            $record = static::create();
            $record->write();
        }
        return $record;
    }

    /**
     * Ensures at least one SchedulerConfig record exists in the database.
     *
     * @inheritdoc
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        if (static::get()->count() === 0) {
            $record = static::create();
            $record->write();
            DB::alteration_message('Created default SchedulerConfig record', 'created');
        }
    }

    /**
     * @inheritdoc
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Jobs'] = _t(__CLASS__ . '.JOBS', 'Jobs');
        $labels['Runs'] = _t(__CLASS__ . '.RUNS', 'History');
        $labels['Settings'] = _t(__CLASS__ . '.SETTINGS', 'Settings');
        $labels['NotifyMembers'] = _t(__CLASS__ . '.NOTIFY_MEMBERS', 'Notify on failure');
        return $labels;
    }

    /**
     * @inheritdoc
     */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Jobs', 'Runs']);

        /** @var GridField $jobsField */
        $jobsField = GridField::create(
            'Jobs',
            '',
            $this->Jobs(),
            GridFieldConfig_JobsInSchedulerAdmin::create()
        );

        /** @var GridField $runsField */
        $runsField = GridField::create(
            'Runs',
            '',
            ScheduledJobRun::get()->sort('Created DESC')->limit(200),
            GridFieldConfig_RunsInSchedulerAdmin::create()
        );

        $fields->addFieldToTab('Root.Main', $this->buildRunAllBar());

        $notifyField = ListboxField::create(
            'NotifyMembers',
            $this->fieldLabel('NotifyMembers'),
            Member::get()->map('ID', 'Name')->toArray()
        );
        $notifyField->setDescription(_t(
            __CLASS__ . '.NOTIFY_MEMBERS_DESC',
            'Empfänger einer Benachrichtigungs-E-Mail, wenn beim „Alle Tasks nacheinander"-Durchlauf ein oder mehrere Tasks fehlgeschlagen sind.'
        ));
        $fields->addFieldToTab('Root.Main', $jobsField);
        $fields->fieldByName('Root.Main')->setTitle($this->fieldLabel('Jobs'));

        $fields->addFieldToTab('Root.' . $this->fieldLabel('Runs'), $runsField);
        $fields->addFieldToTab('Root.' . $this->fieldLabel('Settings'), $notifyField);

        return $fields;
    }

    /**
     * Returns a human-readable title.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Scheduler');
    }

    /**
     * Builds the "Run all" action shown above the Jobs GridField.
     *
     * Serialises the enabled jobs (in Sort order) into a data attribute so
     * the JS widget can dispatch them sequentially, colour-code each segment
     * of the progress bar, and estimate durations from the last run.
     *
     * @return LiteralField
     */
    private function buildRunAllBar(): LiteralField
    {
        $palette = [
            '#0d6efd', '#20c997', '#fd7e14', '#6f42c1',
            '#d63384', '#198754', '#ffc107', '#dc3545',
            '#0dcaf0', '#6610f2',
        ];
        $fallbackMs = 10000;

        $tasks = [];
        $jobs = $this->Jobs()->filter('Enabled', 1)->sort(['Sort' => 'ASC', 'ID' => 'ASC']);
        $index = 0;
        /** @var ScheduledJob $job */
        foreach ($jobs as $job) {
            $estimated = (int) $job->LastDurationMs;
            if ($estimated <= 0) {
                $estimated = $fallbackMs;
            }
            $tasks[] = [
                'id'          => (int) $job->ID,
                'name'        => (string) $job->getTitle(),
                'color'       => $palette[$index % count($palette)],
                'estimatedMs' => $estimated,
                'dispatchUrl' => Controller::join_links('admin', 'scheduler', 'dispatch-job', (string) $job->ID),
                'statusUrl'   => Controller::join_links('admin', 'scheduler', 'job-status', (string) $job->ID),
            ];
            $index++;
        }

        $json = htmlspecialchars(json_encode($tasks, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        $btnLabel = _t(__CLASS__ . '.RUN_ALL', 'Run all tasks');
        $notifyUrl = Controller::join_links('admin', 'scheduler', 'notify-failed');

        $html = '<div class="scheduler-run-all" data-scheduler-run-all data-tasks="' . $json . '"'
            . ' data-notify-url="' . htmlspecialchars($notifyUrl, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="button" class="btn btn-primary scheduler-run-all__trigger">'
            . htmlspecialchars($btnLabel, ENT_QUOTES, 'UTF-8')
            . '</button>'
            . '<div class="scheduler-run-all__progress" hidden>'
            . '<div class="scheduler-run-all__row">'
            . '<div class="scheduler-run-all__bar">'
            . '<div class="scheduler-run-all__segments" data-segments></div>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '<div class="scheduler-run-all__status" data-status></div>'
            . '</div>';

        return LiteralField::create('SchedulerRunAllBar', $html);
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
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /**
     * The single SchedulerConfig record must not be deleted.
     *
     * @inheritdoc
     */
    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}
