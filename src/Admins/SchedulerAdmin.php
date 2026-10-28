<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Admins;

use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJob;
use Clesson\Silverstripe\Scheduler\Models\SchedulerConfig;
use Clesson\Silverstripe\Scheduler\Services\JobRunnerService;
use SilverStripe\Admin\SingleRecordAdmin;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * SingleRecordAdmin for managing scheduled jobs and their execution history.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Admins
 */
class SchedulerAdmin extends SingleRecordAdmin
{
    /**
     * @inheritdoc
     */
    private static array $allowed_actions = [
        'jobStatus',
        'dispatchJob',
        'notifyFailed',
    ];

    /**
     * @inheritdoc
     */
    private static array $url_handlers = [
        'job-status/$JobID' => 'jobStatus',
        'dispatch-job/$JobID' => 'dispatchJob',
        'notify-failed' => 'notifyFailed',
    ];

    /**
     * @config
     */
    private static string $url_segment = 'scheduler';

    /**
     * @config
     */
    private static string $menu_title = 'Scheduler';

    /**
     * @config
     */
    private static float $menu_priority = -1.1;

    /**
     * @config
     */
    private static string $menu_icon_class = 'font-icon-clock';

    /**
     * @config
     */
    private static string $model_class = SchedulerConfig::class;

    /**
     * @config
     */
    private static bool $restrict_to_single_record = true;

    /**
     * @config
     */
    private static bool $allow_new_record = true;

    /**
     * Returns the current runtime status of a single ScheduledJob as JSON.
     *
     * Used by the "Run now" button to poll for completion without a page
     * reload. The response contains the last-run timestamp, the last status,
     * and whether the job is currently running.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function jobStatus(HTTPRequest $request): HTTPResponse
    {
        $jobId = (int) $request->param('JobID');
        /** @var ScheduledJob|null $job */
        $job = $jobId > 0 ? ScheduledJob::get()->byID($jobId) : null;

        $response = HTTPResponse::create();
        $response->addHeader('Content-Type', 'application/json');
        $response->addHeader('Cache-Control', 'no-store');

        if (!$job) {
            $response->setStatusCode(404);
            $response->setBody(json_encode(['error' => 'not_found']));
            return $response;
        }

        $status = (string) $job->LastStatus;
        $response->setBody(json_encode([
            'id'             => (int) $job->ID,
            'running'        => (bool) $job->RunningSince,
            'lastRunAt'      => $job->LastRunAt ? (string) $job->obj('LastRunAt')->Nice() : '',
            'lastRunAtRaw'   => (string) $job->LastRunAt,
            'lastStatus'     => $status,
            'lastStatusLabel' => $status !== '' ? JobStatus::label($status) : '',
            'lastDurationMs' => (int) $job->LastDurationMs,
        ]));
        return $response;
    }

    /**
     * Dispatches a single ScheduledJob to run in a detached subprocess and
     * returns the created run's identifier as JSON. Used by the "Run all"
     * bar to trigger sequential job execution from the browser.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function dispatchJob(HTTPRequest $request): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->addHeader('Content-Type', 'application/json');
        $response->addHeader('Cache-Control', 'no-store');

        if (!$request->isPOST()) {
            $response->setStatusCode(405);
            $response->setBody(json_encode(['error' => 'method_not_allowed']));
            return $response;
        }

        $jobId = (int) $request->param('JobID');
        /** @var ScheduledJob|null $job */
        $job = $jobId > 0 ? ScheduledJob::get()->byID($jobId) : null;
        if (!$job) {
            $response->setStatusCode(404);
            $response->setBody(json_encode(['error' => 'not_found']));
            return $response;
        }

        if ((bool) $job->RunningSince) {
            $response->setBody(json_encode([
                'id'      => (int) $job->ID,
                'running' => true,
                'skipped' => true,
            ]));
            return $response;
        }

        $run = JobRunnerService::create()->dispatchJobDetached($job);

        $response->setBody(json_encode([
            'id'      => (int) $job->ID,
            'running' => true,
            'runID'   => (int) $run->ID,
        ]));
        return $response;
    }

    /**
     * Sends a failure-notification email to every member configured on the
     * SchedulerConfig record, listing the tasks that failed in the current
     * "Run all" batch. Expects a POST body with a `jobIDs` array (or CSV).
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function notifyFailed(HTTPRequest $request): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->addHeader('Content-Type', 'application/json');
        $response->addHeader('Cache-Control', 'no-store');

        if (!$request->isPOST()) {
            $response->setStatusCode(405);
            $response->setBody(json_encode(['error' => 'method_not_allowed']));
            return $response;
        }

        $raw = $request->postVar('jobIDs');
        $ids = [];
        if (is_array($raw)) {
            $ids = array_map('intval', $raw);
        } elseif (is_string($raw) && $raw !== '') {
            $ids = array_map('intval', explode(',', $raw));
        }
        $ids = array_values(array_filter($ids, static fn(int $i): bool => $i > 0));

        if (!$ids) {
            $response->setBody(json_encode(['sent' => 0, 'reason' => 'no_ids']));
            return $response;
        }

        $config = SchedulerConfig::currentRecord();
        $recipients = $config->NotifyMembers();
        if (!$recipients->count()) {
            $response->setBody(json_encode(['sent' => 0, 'reason' => 'no_recipients']));
            return $response;
        }

        /** @var ScheduledJob[] $failedJobs */
        $failedJobs = ScheduledJob::get()->filter('ID', $ids)->toArray();
        if (!$failedJobs) {
            $response->setBody(json_encode(['sent' => 0, 'reason' => 'jobs_not_found']));
            return $response;
        }

        $siteName = (string) SiteConfig::current_site_config()->Title;
        $subject = sprintf('[%s] Scheduler: %d Task(s) fehlgeschlagen', $siteName ?: 'Scheduler', count($failedJobs));

        $rows = '';
        foreach ($failedJobs as $job) {
            $rows .= '<li><strong>' . htmlspecialchars((string) $job->getTitle(), ENT_QUOTES, 'UTF-8') . '</strong>'
                . ' — ' . htmlspecialchars((string) JobStatus::label((string) $job->LastStatus), ENT_QUOTES, 'UTF-8')
                . ($job->LastMessage ? ': ' . htmlspecialchars((string) $job->LastMessage, ENT_QUOTES, 'UTF-8') : '')
                . '</li>';
        }

        $body = '<p>Beim manuellen Durchlauf im Scheduler-Admin sind folgende Tasks fehlgeschlagen:</p>'
            . '<ul>' . $rows . '</ul>'
            . '<p>Details siehe Scheduler → Verlauf.</p>';

        $sent = 0;
        $from = (string) Email::config()->get('admin_email');
        foreach ($recipients as $member) {
            if (!$member->Email) {
                continue;
            }
            $email = Email::create();
            if ($from !== '') {
                $email->setFrom($from);
            }
            $email->setTo((string) $member->Email);
            $email->setSubject($subject);
            $email->setBody($body);
            $email->send();
            $sent++;
        }

        $response->setBody(json_encode([
            'sent' => $sent,
            'failedJobIDs' => array_map(static fn(ScheduledJob $j): int => (int) $j->ID, $failedJobs),
        ]));
        return $response;
    }
}
