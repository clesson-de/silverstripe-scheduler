<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Forms\GridField;

use Clesson\Silverstripe\Scheduler\Models\ScheduledJob;
use Clesson\Silverstripe\Scheduler\Services\JobRunnerService;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\ORM\DataObject;

/**
 * GridField component that renders a "Run now" button per ScheduledJob row.
 *
 * Clicking the button immediately dispatches the job in an isolated subprocess,
 * bypassing the cron schedule and the Enabled flag.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Forms
 */
class GridFieldAction_RunNow implements GridField_ColumnProvider, GridField_ActionProvider
{
    use Injectable;

    /**
     * @param GridField $gridField
     * @param array $columns
     * @return void
     */
    public function augmentColumns($gridField, &$columns): void
    {
        if (!in_array('RunNow', $columns, true)) {
            $columns[] = 'RunNow';
        }
    }

    /**
     * @param GridField $gridField
     * @param string $columnName
     * @return array
     */
    public function getColumnMetadata($gridField, $columnName): array
    {
        if ($columnName === 'RunNow') {
            return ['title' => _t(__CLASS__ . '.RUN_NOW', 'Run')];
        }
        return [];
    }

    /**
     * @param GridField $gridField
     * @return array
     */
    public function getColumnsHandled($gridField): array
    {
        return ['RunNow'];
    }

    /**
     * @param GridField $gridField
     * @param DataObject $record
     * @param string $columnName
     * @return array
     */
    public function getColumnAttributes($gridField, $record, $columnName): array
    {
        return ['class' => 'grid-field__col-compact'];
    }

    /**
     * @param GridField $gridField
     * @param DataObject $record
     * @param string $columnName
     * @return string
     */
    public function getColumnContent($gridField, $record, $columnName): string
    {
        if (!$record instanceof ScheduledJob) {
            return '';
        }
        if (!$record->TaskClass) {
            return '';
        }

        /** @var GridField_FormAction $action */
        $action = GridField_FormAction::create(
            $gridField,
            'RunNow_' . $record->ID,
            '▶',
            'runnow',
            ['RecordID' => (int) $record->ID]
        );

        $action->addExtraClass('grid-field__icon-action btn--no-text no-change-track scheduler-runnow-action');
        $action->setAttribute('style', 'color:#0d6efd;font-size:1.1em;border:none;background:none;cursor:pointer;');
        $action->setAttribute('title', _t(__CLASS__ . '.RUN_NOW_TITLE', 'Run job now'));
        $action->setAttribute('data-scheduler-runnow', (string) $record->ID);
        $action->setAttribute(
            'data-scheduler-status-url',
            Controller::join_links('admin', 'scheduler', 'job-status', (string) $record->ID)
        );

        return (string) $action->Field();
    }

    /**
     * @param GridField $gridField
     * @return array
     */
    public function getActions($gridField): array
    {
        return ['runnow'];
    }

    /**
     * Dispatches the job immediately via JobRunnerService.
     *
     * @param GridField $gridField
     * @param string $actionName
     * @param array $arguments
     * @param array $data
     * @return void
     */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data): void
    {
        if ($actionName !== 'runnow') {
            return;
        }

        $recordID = (int) ($arguments['RecordID'] ?? 0);
        if ($recordID === 0) {
            return;
        }

        /** @var ScheduledJob|null $job */
        $job = ScheduledJob::get()->byID($recordID);
        if (!$job) {
            return;
        }

        /** @var JobRunnerService $service */
        $service = Injector::inst()->create(JobRunnerService::class);
        $service->dispatchJobDetached($job);
    }
}
