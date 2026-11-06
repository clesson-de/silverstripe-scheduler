<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Forms\GridField;

use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJobRun;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Model\List\SS_List;

/**
 * Filters execution history by success or failure while retaining other GridField state.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Forms\GridField
 */
class GridFieldFilter_RunStatus implements GridField_HTMLProvider, GridField_DataManipulator, GridField_ActionProvider
{
    use Injectable;

    /** Stores the applied status independently of the search query. */
    public const STATE = 'GridFieldRunStatusFilter';
    /** Names the native GridField filter action. */
    public const ACTION = 'filterrunsbystatus';
    /** Uses a grid-specific input name to avoid collisions in nested forms. */
    public const INPUT = 'RunStatus';

    /** Receives the shared toolbar slot. */
    public function __construct(private string $fragment)
    {
    }

    /** Provides the requested statuses plus an unfiltered option. */
    private function options(): array
    {
        return [
            '' => _t(__CLASS__ . '.ALL_STATUSES', 'All statuses'),
            JobStatus::SUCCESS => JobStatus::label(JobStatus::SUCCESS),
            JobStatus::FAILURE => JobStatus::label(JobStatus::FAILURE),
        ];
    }

    /** Rejects malformed requests and URL state instead of silently ignoring them. */
    private function validateStatus(mixed $value): string
    {
        if (!is_string($value) || !array_key_exists($value, $this->options())) {
            throw new HTTPResponse_Exception('Invalid run status filter.', 400);
        }
        return $value;
    }

    /** Declares the native action used by the dropdown. */
    public function getActions($gridField): array
    {
        return [self::ACTION];
    }

    /** Updates only status state and resets pagination when available. */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data): void
    {
        if ($actionName !== self::ACTION) {
            return;
        }
        $gridField->State->{self::STATE} = $this->validateStatus($data[$gridField->getName()][self::INPUT] ?? '');
        if (isset($gridField->State->GridFieldPaginator)) {
            $gridField->State->GridFieldPaginator->currentPage = 1;
        }
    }

    /** Uses native CMS submission, leaving search and unrelated form state intact. */
    public function getHTMLFragments($gridField): array
    {
        $label = _t(ScheduledJobRun::class . '.STATUS', 'Status');
        $dropdown = DropdownField::create($gridField->getName() . '[' . self::INPUT . ']', '', $this->options());
        $dropdown->setValue($this->validateStatus($gridField->State->{self::STATE} ?? ''));
        $dropdown->setAttribute('aria-label', $label);
        $dropdown->addExtraClass('no-change-track dropdown');
        $dropdown->setAttribute('style', 'min-width:180px;');
        $dropdown->setAttribute('onchange', 'this.parentElement.querySelector("button").click();');
        $action = GridField_FormAction::create($gridField, self::ACTION, $label, self::ACTION, null);
        $action->setAttribute('style', 'display:none;');
        return [$this->fragment => '<div class="scheduler-run-status-filter">'
            . $dropdown->forTemplate() . $action->forTemplate() . '</div>'];
    }

    /** Applies an exact status restriction without replacing existing list scope. */
    public function getManipulatedData(GridField $gridField, SS_List $dataList): SS_List
    {
        $status = $this->validateStatus($gridField->State->{self::STATE} ?? '');
        return $status === '' ? $dataList : $dataList->filter('Status', $status);
    }
}
