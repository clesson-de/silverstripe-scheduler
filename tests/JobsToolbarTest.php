<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Tests;

use Clesson\Silverstripe\Forms\GridField\GridFieldSearch;
use Clesson\Silverstripe\Forms\GridField\GridFieldToolbar;
use Clesson\Silverstripe\Scheduler\Forms\GridField\GridFieldAction_RunNow;
use Clesson\Silverstripe\Scheduler\Forms\GridFieldConfig_JobsInSchedulerAdmin;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJob;
use Clesson\Silverstripe\Scheduler\Tasks\SchedulerRunTask;
use DOMDocument;
use DOMXPath;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\i18n\i18n;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * Covers the scheduler job toolbar and the shared search without changing job actions.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Tests
 */
class JobsToolbarTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** Builds a real form so toolbar fragments and creation permissions are resolved. */
    private function grid(): GridField
    {
        $this->logInWithPermission('ADMIN');
        $grid = GridField::create('Jobs', '', ScheduledJob::get(), GridFieldConfig_JobsInSchedulerAdmin::create());
        $form = Form::create(Controller::curr(), 'JobsToolbarTest', FieldList::create($grid), FieldList::create());
        $form->setFormAction('/test');
        return $grid;
    }

    /** One toolbar contains a left search and exactly one right creation action. */
    public function testToolbarPlacementAndExistingActions(): void
    {
        $grid = $this->grid();
        $config = $grid->getConfig();
        self::assertInstanceOf(GridFieldToolbar::class, $config->getComponentByType(GridFieldButtonRow::class));
        self::assertCount(1, $config->getComponentsByType(GridFieldButtonRow::class));
        foreach ([
            GridFieldAddNewButton::class, GridFieldEditButton::class, GridFieldDeleteAction::class,
            GridFieldDetailForm::class, GridFieldAction_RunNow::class, GridFieldOrderableRows::class,
        ] as $type) {
            self::assertNotNull($config->getComponentByType($type));
        }
        $html = (string) $grid->FieldHolder();
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        self::assertCount(1, $xpath->query(
            '//*[contains(@class, "gridfield-pro-toolbar__group--left")]//input[contains(@class, "gridfield-search__input")]'
        ));
        self::assertCount(1, $xpath->query(
            '//*[contains(@class, "gridfield-pro-toolbar__group--right")]//a[contains(@href, "/item/new")]'
        ));
        self::assertCount(1, $xpath->query('//a[contains(@href, "/item/new")]'));
        self::assertStringNotContainsString('$DefineFragment', $html);
    }

    /** Case-insensitive OR matches preserve the supplied scope and ordering; clearing restores the list. */
    public function testSearchAndClear(): void
    {
        $grid = $this->grid();
        $descriptionMatch = ScheduledJob::create(['Description' => 'SchedulerRun description', 'Sort' => 1]);
        $descriptionMatch->write();
        $taskMatch = ScheduledJob::create(['TaskClass' => SchedulerRunTask::class, 'Description' => 'Other', 'Sort' => 2]);
        $taskMatch->write();
        $excluded = ScheduledJob::create(['TaskClass' => SchedulerRunTask::class, 'Sort' => 3]);
        $excluded->write();
        $list = ScheduledJob::get()->exclude('ID', $excluded->ID);
        $grid->setList($list);
        $search = $grid->getConfig()->getComponentByType(GridFieldSearch::class);
        self::assertSame(['TaskClass', 'Description'], $search->getSearchFields());
        self::assertSame('Suche in Task und Beschreibung', i18n::with_locale('de_DE', fn () => $search->getPlaceholder($grid)));
        foreach (['schedulerrun', 'missing', ''] as $term) {
            $search->handleAction($grid, GridFieldSearch::ACTION, [], ['Jobs' => [GridFieldSearch::QUERY => $term]]);
            self::assertSame(
                $term === 'missing' ? [] : [$descriptionMatch->ID, $taskMatch->ID],
                $search->getManipulatedData($grid, $list)->columnUnique('ID')
            );
        }
    }
}
