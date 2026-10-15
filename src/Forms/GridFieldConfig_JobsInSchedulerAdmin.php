<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Forms;

use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use Clesson\Silverstripe\Scheduler\Forms\GridField\GridFieldAction_RunNow;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJob;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\ORM\FieldType\DBField;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * GridField configuration for the Jobs list in SchedulerAdmin.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Forms
 */
class GridFieldConfig_JobsInSchedulerAdmin extends GridFieldConfig
{
    /**
     * @param int|null $itemsPerPage
     * @param bool|null $showPagination
     * @param bool|null $showAdd
     */
    public function __construct($itemsPerPage = null, $showPagination = null, $showAdd = null)
    {
        parent::__construct();

        $this->addComponent(GridFieldButtonRow::create('before'));
        $this->addComponent(GridFieldAddNewButton::create('buttons-before-right'));
        $this->addComponent(GridFieldSortableHeader::create());
        $this->addComponent($dataColumns = GridFieldDataColumns::create());
        $this->addComponent(GridFieldAction_RunNow::create());
        $this->addComponent(GridFieldEditButton::create());
        $this->addComponent(GridFieldDeleteAction::create());
        $this->addComponent(GridFieldOrderableRows::create('Sort'));

        $dataColumns->setDisplayFields([
            'TaskClass' => [
                'title' => _t(ScheduledJob::class . '.TASK_CLASS', 'Task'),
                'callback' => static function ($record): DBField {
                    $label = '';
                    if ($record->TaskClass && class_exists($record->TaskClass)) {
                        try {
                            $instance = \SilverStripe\Core\Injector\Injector::inst()->create($record->TaskClass);
                            $label = $instance->getTitle();
                        } catch (\Throwable) {
                            // fall through
                        }
                    }
                    if ($label === '' && $record->TaskClass) {
                        $label = substr(strrchr($record->TaskClass, '\\') ?: $record->TaskClass, 1) ?: $record->TaskClass;
                    }
                    $safe = htmlspecialchars($label ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    if (!$record->Enabled) {
                        $safe = '<span style="text-decoration:line-through;color:#888;">' . $safe . '</span>';
                    }
                    return DBField::create_field('HTMLFragment', $safe);
                },
            ],
            'Description' => [
                'title' => _t(ScheduledJob::class . '.DESCRIPTION', 'Description'),
                'callback' => static function ($record): DBField {
                    $text = (string) $record->Description;
                    if ($text === '') {
                        return DBField::create_field('HTMLFragment', '<span style="font-size:0.8em;color:#888;">—</span>');
                    }
                    $safe = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                    return DBField::create_field(
                        'HTMLFragment',
                        '<span style="font-size:0.8em;line-height:1.3;display:inline-block;">' . $safe . '</span>'
                    );
                },
            ],
            'CronExpression' => [
                'title' => _t(ScheduledJob::class . '.CRON_EXPRESSION', 'Cron'),
                'callback' => static function ($record): DBField {
                    return DBField::create_field('Varchar', $record->CronExpression);
                },
            ],
            'LastRunAt' => [
                'title' => _t(ScheduledJob::class . '.LAST_RUN_AT', 'Last run'),
                'callback' => static function ($record): DBField {
                    return DBField::create_field('DBDatetime', $record->LastRunAt);
                },
            ],
            'LastStatus' => [
                'title' => _t(ScheduledJob::class . '.LAST_STATUS', 'Last status'),
                'callback' => static function ($record): DBField {
                    return DBField::create_field('Varchar', JobStatus::label((string) $record->LastStatus));
                },
            ],
        ]);

        $this->addComponent(GridFieldDetailForm::create(null, $showPagination, $showAdd));
        $this->extend('updateConfig');
    }
}
