<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Services;

use Clesson\Silverstripe\Scheduler\Constants\JobStatus;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJob;
use Clesson\Silverstripe\Scheduler\Models\ScheduledJobRun;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Orchestrates the periodic run of all due ScheduledJobs.
 *
 * Each job is executed in an isolated subprocess (`sake dev/tasks/scheduled-job-execute`)
 * so that a failing or hanging job cannot block subsequent jobs.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Services
 */
class JobRunnerService
{
    use Configurable;
    use Injectable;

    /**
     * @config
     */
    private static int $default_subprocess_timeout = 900;

    /**
     * @config
     */
    private static string $sake_binary = '';

    /**
     * Executes all jobs that are due at the current moment.
     *
     * @param OutputInterface $output
     * @return void
     */
    public function runDueJobs(OutputInterface $output): void
    {
        $globalLock = $this->acquireGlobalLock();
        if ($globalLock === null) {
            $output->writeln('<comment>Another scheduler runner is already active — skipping.</comment>');
            return;
        }

        try {
            $now = new \DateTimeImmutable();

            /** @var ScheduledJob $job */
            foreach (ScheduledJob::get() as $job) {
                try {
                    $this->processJob($job, $now, $output);
                } catch (\Throwable $e) {
                    $output->writeln(sprintf(
                        '<error>Failed to process job "%s" (%d): %s</error>',
                        $job->Title, (int) $job->ID, $e->getMessage()
                    ));
                }
            }
        } finally {
            $this->releaseGlobalLock($globalLock);
        }
    }

    /**
     * Handles a single job: staleness check, due check, subprocess dispatch.
     *
     * @param ScheduledJob $job
     * @param \DateTimeInterface $now
     * @param OutputInterface $output
     * @return void
     */
    private function processJob(ScheduledJob $job, \DateTimeInterface $now, OutputInterface $output): void
    {
        // Release stuck locks whose runtime has exceeded the configured maximum.
        if ($job->RunningSince) {
            $runningSince = new \DateTimeImmutable((string) $job->RunningSince);
            $maxSeconds = ((int) $job->MaxRuntimeSeconds) > 0
                ? (int) $job->MaxRuntimeSeconds
                : static::config()->get('default_subprocess_timeout');

            if (($now->getTimestamp() - $runningSince->getTimestamp()) > $maxSeconds) {
                $output->writeln(sprintf(
                    '<comment>Job "%s" appears stuck (running since %s) — releasing lock.</comment>',
                    $job->Title, (string) $job->RunningSince
                ));
                $this->finalizeStaleRun($job);
            } else {
                // Still legitimately running from a previous invocation.
                return;
            }
        }

        if (!$job->isDue($now)) {
            return;
        }

        $this->dispatchJob($job, $output);
    }

    /**
     * Starts a subprocess for the given job and records the outcome.
     *
     * @param ScheduledJob $job
     * @param OutputInterface $output
     * @return void
     */
    public function dispatchJob(ScheduledJob $job, OutputInterface $output): void
    {
        $run = ScheduledJobRun::create();
        $run->JobID = (int) $job->ID;
        $run->ConfigID = (int) $job->ConfigID;
        $run->Status = JobStatus::RUNNING;
        $run->StartedAt = DBDatetime::now()->getValue();
        $run->write();

        $job->RunningSince = DBDatetime::now()->getValue();
        $job->write();

        $timeout = ((int) $job->MaxRuntimeSeconds) > 0
            ? (int) $job->MaxRuntimeSeconds
            : (int) static::config()->get('default_subprocess_timeout');

        $command = $this->buildSubprocessCommand($job, (int) $run->ID);
        $output->writeln(sprintf('Dispatching "%s" (job #%d) — %s', $job->Title, (int) $job->ID, $command));

        $process = Process::fromShellCommandline($command, Director::baseFolder());
        $process->setTimeout($timeout);

        $started = microtime(true);
        $status = JobStatus::FAILURE;
        $exitCode = -1;
        $stdout = '';
        $stderr = '';

        try {
            $process->run();
            $exitCode = (int) $process->getExitCode();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $status = $exitCode === 0 ? JobStatus::SUCCESS : JobStatus::FAILURE;
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $status = JobStatus::TIMEOUT;
            $stderr = $e->getMessage();
            try {
                $process->stop(3, SIGKILL);
            } catch (\Throwable) {
                // ignore
            }
        } catch (\Throwable $e) {
            $status = JobStatus::FAILURE;
            $stderr = $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $run->Status = $status;
        $run->FinishedAt = DBDatetime::now()->getValue();
        $run->DurationMs = $durationMs;
        $run->ExitCode = $exitCode;
        $run->Output = $this->truncate($stdout, 60000);
        $run->ErrorOutput = $this->truncate($stderr, 20000);
        $run->write();

        $job->LastRunAt = $run->StartedAt;
        $job->LastStatus = $status;
        $job->LastMessage = $this->truncate(trim($stderr ?: $stdout), 2000);
        $job->LastDurationMs = $durationMs;
        $job->RunningSince = null;
        $job->write();

        $output->writeln(sprintf(
            '  → %s (exit %d, %d ms)',
            $status, $exitCode, $durationMs
        ));
    }

    /**
     * Launches the job as a detached background process and returns immediately.
     *
     * The executor subprocess is responsible for updating the ScheduledJobRun
     * record when it finishes. Suitable for "Run now" from the CMS where the
     * HTTP request must not block for the full job duration.
     *
     * @param ScheduledJob $job
     * @return ScheduledJobRun The pre-created run record (status RUNNING).
     */
    public function dispatchJobDetached(ScheduledJob $job): ScheduledJobRun
    {
        $run = ScheduledJobRun::create();
        $run->JobID = (int) $job->ID;
        $run->ConfigID = (int) $job->ConfigID;
        $run->Status = JobStatus::RUNNING;
        $run->StartedAt = DBDatetime::now()->getValue();
        $run->write();

        $job->RunningSince = DBDatetime::now()->getValue();
        $job->write();

        $sake = $this->resolveSakeBinary();
        $logFile = sys_get_temp_dir()
            . '/silverstripe-scheduler-job-' . (int) $job->ID . '-' . (int) $run->ID . '.log';

        // nohup + trailing "&" fully detaches the child so the PHP-FPM worker
        // can return immediately. Output is redirected to a log file to keep
        // stdout/stderr from being tied to the parent process.
        $command = sprintf(
            'nohup %s dev/tasks/scheduled-job-execute --jobID=%d --runID=%d > %s 2>&1 &',
            escapeshellcmd($sake),
            (int) $job->ID,
            (int) $run->ID,
            escapeshellarg($logFile)
        );

        $process = Process::fromShellCommandline($command, Director::baseFolder());
        $process->setTimeout(10);
        $process->run();

        return $run;
    }

    /**
     * Marks a stuck job's last run as TIMEOUT and clears the RunningSince lock.
     *
     * @param ScheduledJob $job
     * @return void
     */
    private function finalizeStaleRun(ScheduledJob $job): void
    {
        $lastRun = ScheduledJobRun::get()
            ->filter(['JobID' => (int) $job->ID, 'Status' => JobStatus::RUNNING])
            ->sort('Created DESC')
            ->first();

        if ($lastRun) {
            $lastRun->Status = JobStatus::TIMEOUT;
            $lastRun->FinishedAt = DBDatetime::now()->getValue();
            $lastRun->ErrorOutput = 'Runner detected stale RunningSince lock — job did not finish within its maximum runtime.';
            $lastRun->write();
        }

        $job->LastStatus = JobStatus::TIMEOUT;
        $job->RunningSince = null;
        $job->write();
    }

    /**
     * Builds the shell command that runs a single job in an isolated subprocess.
     *
     * @param ScheduledJob $job
     * @return string
     */
    private function buildSubprocessCommand(ScheduledJob $job, int $runId = 0): string
    {
        $sake = $this->resolveSakeBinary();
        $runOpt = $runId > 0 ? ' --runID=' . $runId : '';
        return sprintf(
            '%s dev/tasks/scheduled-job-execute --jobID=%d%s 2>&1',
            escapeshellcmd($sake),
            (int) $job->ID,
            $runOpt
        );
    }

    /**
     * Locates the sake binary either via config or auto-detection.
     *
     * @return string
     */
    private function resolveSakeBinary(): string
    {
        $configured = (string) static::config()->get('sake_binary');
        if ($configured !== '') {
            return $configured;
        }

        $candidate = Director::baseFolder() . '/vendor/bin/sake';
        if (is_executable($candidate)) {
            return $candidate;
        }
        return 'sake';
    }

    /**
     * Acquires an exclusive filesystem lock so that only one runner is active
     * at a time. Returns the file handle to release later, or null if the lock
     * could not be acquired.
     *
     * @return resource|null
     */
    private function acquireGlobalLock()
    {
        $tempDir = sys_get_temp_dir();
        $lockFile = $tempDir . '/silverstripe-scheduler-' . md5(Director::baseFolder()) . '.lock';

        $handle = @fopen($lockFile, 'c');
        if (!$handle) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /**
     * Releases the global runner lock.
     *
     * @param resource $handle
     * @return void
     */
    private function releaseGlobalLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
