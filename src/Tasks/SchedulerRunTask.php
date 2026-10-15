<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\Scheduler\Tasks;

use Clesson\Silverstripe\Scheduler\Services\JobRunnerService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The master runner task, invoked by the server's cron every N minutes.
 *
 * Iterates all ScheduledJobs, determines which are due, and dispatches
 * each in an isolated subprocess so that failures do not cascade.
 *
 * @package Clesson\Silverstripe\Scheduler
 * @subpackage Tasks
 */
class SchedulerRunTask extends BuildTask
{
    /**
     * @inheritdoc
     */
    protected static string $commandName = 'scheduler-run';

    /**
     * @config
     */
    private static ?string $cli_user_email = null;

    /**
     * @inheritdoc
     */
    protected string $title = 'Scheduler — run due jobs';

    /**
     * @inheritdoc
     */
    protected static string $description = 'Runs all scheduled jobs that are due. Intended to be invoked by the server cron every N minutes.';

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

        /** @var JobRunnerService $service */
        $service = Injector::inst()->create(JobRunnerService::class);

        try {
            $service->runDueJobs($output);
        } catch (\Throwable $e) {
            $output->writeln('<error>Scheduler runner failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
