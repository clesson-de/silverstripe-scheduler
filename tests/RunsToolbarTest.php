<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Tests;

use Clesson\Silverstripe\Forms\GridField\GridFieldSearch;
use Clesson\Silverstripe\Forms\GridField\GridFieldToolbar;
use Clesson\Silverstripe\Scheduler\Forms\GridFieldConfig_RunsInSchedulerAdmin;
use Clesson\Silverstripe\Scheduler\Forms\GridField\GridFieldFilter_RunStatus;
use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJob;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJobRun;
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
use SilverStripe\Forms\GridField\GridFieldViewButton;
use SilverStripe\i18n\i18n;

/**
 * Verifies global history search placement, retained actions and list restrictions.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Tests
 */
class RunsToolbarTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** Builds the history grid with the same limit and sorting as the scheduler overview. */
    private function grid(): GridField
    {
        $this->logInWithPermission('ADMIN');
        $grid = GridField::create(
            'Runs', '', ScheduledJobRun::get()->sort('Created DESC')->limit(200),
            GridFieldConfig_RunsInSchedulerAdmin::create()
        );
        $form = Form::create(Controller::curr(), 'RunsToolbarTest', FieldList::create($grid), FieldList::create());
        $form->setFormAction('/test');
        return $grid;
    }

    /** The toolbar has one left search and retains view/delete actions without a creation button. */
    public function testToolbarPlacementAndActions(): void
    {
        $grid = $this->grid();
        $config = $grid->getConfig();
        self::assertInstanceOf(GridFieldToolbar::class, $config->getComponentByType(GridFieldButtonRow::class));
        self::assertCount(1, $config->getComponentsByType(GridFieldButtonRow::class));
        foreach ([GridFieldViewButton::class, GridFieldDeleteAction::class, GridFieldDetailForm::class] as $type) {
            self::assertNotNull($config->getComponentByType($type));
        }
        self::assertNull($config->getComponentByType(GridFieldAddNewButton::class));
        $html = (string) $grid->FieldHolder();
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        self::assertCount(1, $xpath->query(
            '//*[contains(@class, "gridfield-pro-toolbar__group--left")]//input[contains(@class, "gridfield-search__input")]'
        ));
        self::assertStringNotContainsString('$DefineFragment', $html);
        self::assertCount(1, $xpath->query(
            '//*[contains(@class, "gridfield-pro-toolbar__group--left")]//select[@aria-label="Status"]'
        ));
        self::assertCount(3, $xpath->query('//select[@name="Runs[RunStatus]"]/option'));
    }

    /** All four paths match independently, including output from runs whose job is missing. */
    public function testSearchFieldsAndClear(): void
    {
        $grid = $this->grid();
        $job = ScheduledJob::create(['TaskClass' => SchedulerRunTask::class, 'Description' => 'description-token']);
        $job->write();
        $linked = ScheduledJobRun::create(['JobID' => $job->ID]);
        $linked->write();
        $output = ScheduledJobRun::create(['Output' => 'output-token']);
        $output->write();
        $error = ScheduledJobRun::create(['ErrorOutput' => 'error-token']);
        $error->write();
        $search = $grid->getConfig()->getComponentByType(GridFieldSearch::class);
        self::assertSame(['Job.TaskClass', 'Job.Description', 'Output', 'ErrorOutput'], $search->getSearchFields());
        self::assertSame('Suche in Task, Beschreibung, Ausgabe und Fehlerausgabe', i18n::with_locale(
            'de_DE', fn () => $search->getPlaceholder($grid)
        ));
        foreach ([
            'schedulerrun' => $linked->ID, 'description-token' => $linked->ID,
            'output-token' => $output->ID, 'error-token' => $error->ID,
        ] as $term => $expected) {
            $search->handleAction($grid, GridFieldSearch::ACTION, [], ['Runs' => [GridFieldSearch::QUERY => $term]]);
            self::assertSame([$expected], $search->getManipulatedData($grid, $grid->getList())->columnUnique('ID'));
            self::assertSame([], $search->getManipulatedData(
                $grid, $grid->getList()->exclude('ID', $expected)
            )->columnUnique('ID'));
        }
        $search->handleAction($grid, GridFieldSearch::ACTION, [], ['Runs' => [GridFieldSearch::QUERY => 'missing']]);
        self::assertSame(0, $search->getManipulatedData($grid, $grid->getList())->count());
        $search->handleAction($grid, GridFieldSearch::ACTION, [], ['Runs' => [GridFieldSearch::QUERY => '']]);
        self::assertSame($grid->getList(), $search->getManipulatedData($grid, $grid->getList()));
    }

    /** Status and search compose without changing list scope or either component's state. */
    public function testStatusFilterWithSearch(): void
    {
        $grid = $this->grid();
        $filter = $grid->getConfig()->getComponentByType(GridFieldFilter_RunStatus::class);
        $search = $grid->getConfig()->getComponentByType(GridFieldSearch::class);
        $ids = [];
        foreach (array_keys(JobStatus::options()) as $status) {
            $run = ScheduledJobRun::create(['Status' => $status, 'Output' => 'needle']);
            $run->write();
            $ids[$status] = $run->ID;
        }
        $search->handleAction($grid, GridFieldSearch::ACTION, [], ['Runs' => [GridFieldSearch::QUERY => 'needle']]);
        foreach ([JobStatus::SUCCESS, JobStatus::FAILURE, ''] as $status) {
            $grid->State->GridFieldPaginator->currentPage = 4;
            $filter->handleAction($grid, GridFieldFilter_RunStatus::ACTION, [], [
                'Runs' => [GridFieldFilter_RunStatus::INPUT => $status],
            ]);
            $list = $filter->getManipulatedData($grid, $search->getManipulatedData($grid, $grid->getList()));
            self::assertEqualsCanonicalizing($status === '' ? array_values($ids) : [$ids[$status]], $list->columnUnique('ID'));
            self::assertSame('needle', $search->getQuery($grid));
            self::assertSame(1, $grid->State->GridFieldPaginator->currentPage);
        }
        $filter->handleAction($grid, GridFieldFilter_RunStatus::ACTION, [], [
            'Runs' => [GridFieldFilter_RunStatus::INPUT => JobStatus::SUCCESS],
        ]);
        self::assertSame(0, $filter->getManipulatedData(
            $grid, $grid->getList()->exclude('ID', $ids[JobStatus::SUCCESS])
        )->count());
        $search->handleAction($grid, GridFieldSearch::ACTION, [], ['Runs' => [GridFieldSearch::QUERY => '']]);
        self::assertSame(JobStatus::SUCCESS, $grid->State->{GridFieldFilter_RunStatus::STATE});
    }
}
