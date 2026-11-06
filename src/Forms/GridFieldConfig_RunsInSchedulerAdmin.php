<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Forms;

use Clesson\Silverstripe\Constants\GridFieldToolbarLayout;
use Clesson\Silverstripe\Forms\GridField\GridFieldSearch;
use Clesson\Silverstripe\Forms\GridField\GridFieldToolbar;
use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use Clesson\Silverstripe\Scheduler\Forms\GridField\GridFieldFilter_RunStatus;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJobRun;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldViewButton;
use SilverStripe\ORM\FieldType\DBField;

/**
 * GridField configuration for the global history list in SchedulerAdmin.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Forms
 */
class GridFieldConfig_RunsInSchedulerAdmin extends GridFieldConfig
{
    /**
     * @param int|null $itemsPerPage
     * @param bool|null $showPagination
     * @param bool|null $showAdd
     */
    public function __construct($itemsPerPage = null, $showPagination = null, $showAdd = null)
    {
        parent::__construct();

        $this->addToolbar();
        $this->addComponent(GridFieldSortableHeader::create());
        $this->addComponent($dataColumns = GridFieldDataColumns::create());
        $this->addComponent(GridFieldViewButton::create());
        $this->addComponent(GridFieldDeleteAction::create());

        $dataColumns->setDisplayFields([
            'Created' => [
                'title' => _t(ScheduledJobRun::class . '.STARTED_AT', 'Started'),
                'callback' => static function ($record): DBField {
                    return DBField::create_field('DBDatetime', $record->Created);
                },
            ],
            'Job.Title' => [
                'title' => _t(ScheduledJobRun::class . '.JOB', 'Job'),
                'callback' => static function ($record): DBField {
                    return DBField::create_field('Varchar', $record->Job() ? $record->Job()->Title : '—');
                },
            ],
            'Status' => [
                'title' => _t(ScheduledJobRun::class . '.STATUS', 'Status'),
                'callback' => static function ($record): DBField {
                    return DBField::create_field('Varchar', JobStatus::label((string) $record->Status));
                },
            ],
            'DurationMs' => [
                'title' => _t(ScheduledJobRun::class . '.DURATION_MS', 'Duration (ms)'),
                'callback' => static function ($record): DBField {
                    return DBField::create_field('Int', (int) $record->DurationMs);
                },
            ],
            'ExitCode' => [
                'title' => _t(ScheduledJobRun::class . '.EXIT_CODE', 'Exit code'),
                'callback' => static function ($record): DBField {
                    return DBField::create_field('Int', (int) $record->ExitCode);
                },
            ],
        ]);

        $this->addComponent(GridFieldDetailForm::create(null, $showPagination, $showAdd));
        $this->extend('updateConfig');
    }

    /** Adds a compact search without exposing creation of runner-owned history records. */
    private function addToolbar(): void
    {
        $toolbar = GridFieldToolbar::install($this);
        $search = $toolbar->addItem('search', GridFieldToolbarLayout::LEFT, GridFieldToolbarLayout::CONTENT);
        $this->addComponent(GridFieldSearch::create(
            ['Job.TaskClass', 'Job.Description', 'Output', 'ErrorOutput'],
            $search->getFragment()
        ));
        $status = $toolbar->addItem('status', GridFieldToolbarLayout::LEFT, GridFieldToolbarLayout::CONTENT);
        $this->addComponent(GridFieldFilter_RunStatus::create($status->getFragment()));
    }
}
