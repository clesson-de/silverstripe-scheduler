<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Forms;

use SilverStripe\Forms\TextField;
use SilverStripe\View\Requirements;

/**
 * A text field that stores a standard 5-field cron expression, augmented
 * with a user-friendly builder UI. The DB value remains a plain cron
 * string so it stays compatible with dragonmantank/cron-expression.
 *
 * The UI is progressive-enhancement: without JavaScript the field falls
 * back to a plain text input.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Forms
 */
class CronScheduleField extends TextField
{
    /**
     * @inheritdoc
     */
    public function Field($properties = [])
    {
        Requirements::css('clesson-de/silverstripe-scheduler:client/dist/CronScheduleField.css');
        Requirements::javascript('clesson-de/silverstripe-scheduler:client/dist/CronScheduleField.js');

        $this->addExtraClass('cron-schedule-field');
        $this->setAttribute('data-cron-schedule-field', '1');
        return parent::Field($properties);
    }
}
