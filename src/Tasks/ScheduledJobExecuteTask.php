<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Tasks;

use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJob;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJobRun;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Executor task that runs one ScheduledJob's referenced BuildTask.
 *
 * Called by JobRunnerService as a subprocess, so any failure/timeout of the
 * inner task is fully isolated from the master runner and other jobs.
 *
 * Usage: sake tasks:scheduled-job-execute --jobID=42
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Tasks
 */
class ScheduledJobExecuteTask extends BuildTask
{
    /**
     * @inheritdoc
     */
    protected static string $commandName = 'scheduled-job-execute';

    /**
     * @config
     */
    private static ?string $cli_user_email = null;

    /**
     * @inheritdoc
     */
    protected string $title = 'Scheduler — execute single job';

    /**
     * @inheritdoc
     */
    protected static string $description = 'Executes the inner BuildTask of a single ScheduledJob. Called by the runner as a subprocess.';

    /**
     * @inheritdoc
     */
    public function getOptions(): array
    {
        return [
            new InputOption('jobID', null, InputOption::VALUE_REQUIRED, 'ID of the ScheduledJob to execute.'),
            new InputOption('runID', null, InputOption::VALUE_OPTIONAL, 'Optional ID of an existing ScheduledJobRun record to finalise.'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (php_sapi_name() === 'cli') {
            $email = static::config()->get('cli_user_email');
            if (!$email) {
                $output->writeln('<error>cli_user_email is not configured. Cannot run task via CLI without a user context.</error>');
                return Command::FAILURE;
            }
            $member = Member::get()->find('Email', $email);
            if (!$member) {
                $output->writeln("<error>No member found for email '{$email}'. Cannot run task via CLI.</error>");
                return Command::FAILURE;
            }
            Security::setCurrentUser($member);
        }

        $jobId = (int) ($input->getOption('jobID') ?? 0);
        if (!$jobId) {
            $output->writeln('<error>Missing jobID parameter.</error>');
            return Command::FAILURE;
        }

        /** @var ScheduledJob|null $job */
        $job = ScheduledJob::get()->byID($jobId);
        if (!$job) {
            $output->writeln("<error>ScheduledJob #{$jobId} not found.</error>");
            return Command::FAILURE;
        }

        $taskClass = (string) $job->TaskClass;
        if (!$taskClass || !is_subclass_of($taskClass, BuildTask::class)) {
            $output->writeln("<error>Job #{$jobId} has no valid TaskClass ('{$taskClass}').</error>");
            return Command::FAILURE;
        }

        $output->writeln("Executing {$taskClass} for job #{$jobId} ({$job->Title})");

        // Propagate our cli_user_email into the child task's config so that
        // tasks with the same `cli_user_email` pattern (e.g. GitHubSyncTask)
        // pick it up without needing their own configuration entry.
        if (isset($email) && $email && property_exists($taskClass, 'cli_user_email')) {
            Config::modify()->set($taskClass, 'cli_user_email', $email);
        }

        // Optional pre-created run record we should finalise ourselves. Used
        // when the parent dispatched us as a detached background process.
        $runId = (int) ($input->getOption('runID') ?? 0);
        $run = $runId > 0 ? ScheduledJobRun::get()->byID($runId) : null;

        $capture = new BufferedOutput();
        $wrappedOutput = new PolyOutput(PolyOutput::FORMAT_ANSI, $output->getVerbosity(), false, $capture);
        $started = microtime(true);
        $exit = Command::FAILURE;
        $errorMessage = '';

        try {
            /** @var BuildTask $task */
            $task = Injector::inst()->create($taskClass);
            $result = $task->run($input, $wrappedOutput);
            $exit = ($result === null) ? Command::SUCCESS : (int) $result;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage() . "\n" . $e->getTraceAsString();
            $exit = Command::FAILURE;
        }

        $captured = $capture->fetch();
        // Mirror captured output to our own output stream so log files and
        // synchronous callers still see what happened.
        if ($captured !== '') {
            $output->write($captured);
        }
        if ($errorMessage !== '') {
            $output->writeln('<error>Task threw exception: ' . $errorMessage . '</error>');
        }

        if ($run) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $status = $exit === Command::SUCCESS ? JobStatus::SUCCESS : JobStatus::FAILURE;
            $run->Status = $status;
            $run->FinishedAt = DBDatetime::now()->getValue();
            $run->DurationMs = $durationMs;
            $run->ExitCode = $exit;
            $run->Output = $this->truncate($captured, 60000);
            $run->ErrorOutput = $this->truncate($errorMessage, 20000);
            $run->write();

            $job->LastRunAt = $run->StartedAt;
            $job->LastStatus = $status;
            $job->LastMessage = $this->truncate(trim($errorMessage ?: $captured), 2000);
            $job->LastDurationMs = $durationMs;
            $job->RunningSince = null;
            $job->write();
        }

        return $exit;
    }

    /**
     * @param string $value
     * @param int $max
     * @return string
     */
    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max) . '…';
    }
}
